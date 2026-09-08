"""Environment-aware MySQL connection helper for the ML services."""
from __future__ import annotations

import os
from pathlib import Path

import mysql.connector

ROOT_DIR = Path(__file__).resolve().parents[1]


def _load_env_file() -> None:
    env_path = ROOT_DIR / ".env"
    if not env_path.is_file():
        return
    for raw_line in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


_load_env_file()


def get_connection():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST", "localhost"),
        port=int(os.getenv("DB_PORT", "3307")),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
        database=os.getenv("DB_NAME", "inventory_system"),
        charset=os.getenv("DB_CHARSET", "utf8mb4"),
        autocommit=False,
    )
