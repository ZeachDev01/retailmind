-- Inventory draft encryption keys are delivered only to their authenticated Inventory Manager.
CREATE TABLE IF NOT EXISTS inventory_draft_keys (
    user_id INTEGER PRIMARY KEY,
    encryption_key VARCHAR(64) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_submissions (
    user_id INTEGER NOT NULL,
    operation_kind VARCHAR(40) NOT NULL,
    operation_token VARCHAR(64) NOT NULL,
    payload_hash VARCHAR(64) NOT NULL,
    result_json TEXT NOT NULL,
    PRIMARY KEY (user_id, operation_kind, operation_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
