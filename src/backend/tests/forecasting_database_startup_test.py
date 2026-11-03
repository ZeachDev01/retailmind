"""Opt-in actual scheduler/worker recovery against an owned disposable MariaDB process."""
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest
import uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

import pymysql

BACKEND = Path(__file__).resolve().parents[1]


@unittest.skipUnless(os.getenv('RUN_DB_TESTS') == '1', 'Set RUN_DB_TESTS=1 for disposable MariaDB acceptance')
class DatabaseStartupTest(unittest.TestCase):
    def test_scheduler_waits_for_database_then_completes_actual_worker(self):
        binaries = Path('C:/xampp/mysql/bin')
        php = os.getenv('PHP_BINARY', 'C:/xampp/php/php.exe')
        config = BACKEND / 'config/forecasting_runner.json'
        self.assertFalse(config.exists(), 'Use an isolated worktree without installed runner configuration')
        database = 'retailmind_acceptance_' + uuid.uuid4().hex[:12]
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
        with tempfile.TemporaryDirectory(prefix='retailmind_db_startup_') as directory:
            root = Path(directory).resolve()
            data = root / 'data'
            models = root / 'models'
            environment = dict(os.environ, APP_ENV='test', APP_DEBUG='false', DATABASE_ENV='local',
                               DB_HOST='127.0.0.1', DB_PORT=str(port), DB_NAME=database,
                               DB_USER='root', DB_PASSWORD='', DB_PASS='', RUN_DB_TESTS='1',
                               BACKUP_STORAGE_PATH=str(root / 'recovery'))
            flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
            initialized = subprocess.run([str(binaries / 'mysql_install_db.exe'), f'--datadir={data}',
                                          f'--port={port}'], capture_output=True, text=True,
                                         timeout=60, creationflags=flags)
            self.assertEqual(initialized.returncode, 0, initialized.stdout + initialized.stderr)
            server = scheduler = None
            config_created = False
            def start_server():
                return subprocess.Popen([str(binaries / 'mysqld.exe'), '--no-defaults',
                    f'--basedir={binaries.parent}', f'--datadir={data}', '--bind-address=127.0.0.1',
                    f'--port={port}', f'--pid-file={root / "server.pid"}', f'--log-error={root / "server.log"}',
                    '--skip-name-resolve'], creationflags=flags)
            def connect():
                return pymysql.connect(host='127.0.0.1', port=port, user='root', autocommit=True,
                                       cursorclass=pymysql.cursors.DictCursor)
            def ready():
                deadline = time.monotonic() + 20
                while True:
                    try:
                        return connect()
                    except pymysql.Error:
                        self.assertIsNone(server.poll(), (root / 'server.log').read_text(errors='replace'))
                        self.assertLess(time.monotonic(), deadline, 'Owned MariaDB did not become ready')
                        time.sleep(.1)
            try:
                server = start_server()
                connection = ready()
                fixture = subprocess.run([php, str(BACKEND / 'tests/support/operational_pipeline_fixture.php'), 'setup'],
                    env=environment, capture_output=True, text=True, timeout=90, creationflags=flags)
                self.assertEqual(fixture.returncode, 0, fixture.stdout + fixture.stderr)
                origin = datetime.now(ZoneInfo('Asia/Manila')).date() - timedelta(days=1)
                with connection.cursor() as cursor:
                    cursor.execute(f'USE `{database}`')
                    for index in range(260):
                        day = origin - timedelta(days=259-index)
                        cursor.execute('INSERT INTO forecast_sales_imports (product_id,sale_date,quantity,imported_by) VALUES (1,%s,%s,1)',
                                       (day, 5 + day.weekday() * 2 + index // 28))
                queued = subprocess.run([php, str(BACKEND / 'tests/support/operational_pipeline_fixture.php'), 'queue', 'model_retraining'],
                    env=environment, capture_output=True, text=True, timeout=30, creationflags=flags)
                self.assertEqual(queued.returncode, 0, queued.stdout + queued.stderr)
                job_id = json.loads(queued.stdout)['job_id']
                connection.close()
                # SQL SHUTDOWN targets this connection's owned random-port server only.
                connection = connect()
                connection.cursor().execute('SHUTDOWN')
                connection.close()
                server.wait(timeout=20)
                with config.open('x', encoding='utf-8') as handle:
                    config_created = True
                    json.dump({'python_binary': sys.executable, 'model_storage': str(models)}, handle)
                started = datetime.now(ZoneInfo('Asia/Manila')).isoformat()
                app_log = BACKEND / 'storage/logs/app.log'
                log_offset = app_log.stat().st_size if app_log.exists() else 0
                scheduler = subprocess.Popen([php, str(BACKEND / 'scripts/run_scheduler.php'), '--sync'],
                    env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, creationflags=flags)
                time.sleep(2)
                self.assertIsNone(scheduler.poll(), 'Scheduler must remain alive while database is unavailable')
                with self.assertRaises(pymysql.Error):
                    connect()
                server = start_server()
                available = ready()
                available_at = datetime.now(ZoneInfo('Asia/Manila')).isoformat()
                out, err = scheduler.communicate(timeout=120)
                self.assertEqual(scheduler.returncode, 0, out + err)
                with app_log.open('rb') as handle:
                    handle.seek(log_offset)
                    connection_log = handle.read().decode(errors='replace')
                self.assertIn('Database connection failed:', connection_log, 'Must observe a real failed PDO connection before recovery')
                self.assertIn('Executing ml_worker.py synchronously', out)
                with available:
                    with available.cursor() as cursor:
                        cursor.execute(f'USE `{database}`')
                        cursor.execute('SELECT job_id,status,attempts,evidence_cutoff,started_at,completed_at FROM ml_jobs WHERE job_id=%s', (job_id,))
                        job = cursor.fetchone()
                        self.assertEqual(job['status'], 'succeeded', out + err)
                        self.assertEqual(job['attempts'], 1)
                        cursor.execute('SELECT training_run_id,model_version,metrics_json FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1')
                        run = cursor.fetchone()
                        self.assertEqual(json.loads(run['metrics_json'])['metadata']['observations'], 260)
                        self.assertTrue(list(models.rglob('*.joblib')), 'Actual worker must save its model artifact')
                print(out.strip())
                print(err.strip())
                print(connection_log.strip())
                print(json.dumps({'database': database, 'port': port, 'scheduler_started_asia_manila': started,
                    'database_available_asia_manila': available_at, 'job': job,
                    'training_run_id': run['training_run_id'], 'model_version': run['model_version'],
                    'completed_asia_manila': datetime.now(ZoneInfo('Asia/Manila')).isoformat(),
                    'provenance': '260 synthetic import rows; isolated process; not unattended boot'}, default=str))
            finally:
                if scheduler is not None and scheduler.poll() is None:
                    scheduler.kill()
                    scheduler.communicate()
                if server is not None and server.poll() is None:
                    try:
                        connection = connect()
                        connection.cursor().execute('SHUTDOWN')
                        connection.close()
                        server.wait(timeout=20)
                    finally:
                        if server.poll() is None:
                            server.kill()
                            server.wait(timeout=20)
                if config_created:
                    config.unlink()
                # TemporaryDirectory removes only this resolved, owned system-temp root.
                self.assertEqual(root.parent, Path(tempfile.gettempdir()).resolve())
                self.assertTrue(root.name.startswith('retailmind_db_startup_'))


if __name__ == '__main__':
    unittest.main()
