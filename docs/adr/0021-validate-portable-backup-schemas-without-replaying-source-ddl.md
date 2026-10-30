---
status: accepted
---

# Validate portable backup schemas without replaying source DDL

The authorized localhost-to-Hostinger recovery drill exposed different `SHOW CREATE TABLE` representations for the same schema on MySQL 8.4 and MariaDB 11.8. Encrypted packages retain their original authenticated producer schema fingerprint; decoding recognizes only the observed equivalent spellings for integer display widths, redundant column collations, numeric defaults, nullable text defaults, timestamps, simple redundant parentheses, default restrictive foreign keys, and MariaDB's validated JSON alias. String literals, actual collations, types, keys, and constraints must still agree. Incoming replacement uses destination DDL and validated rows, rather than executing producer DDL. This preserves existing encrypted packages while avoiding both blanket schema-hash rejection and unrestricted cross-engine SQL imports.
