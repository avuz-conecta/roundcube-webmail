CREATE TABLE IF NOT EXISTS avuz_filters (
  filter_id  SERIAL PRIMARY KEY,
  user_id    INTEGER NOT NULL,
  name       VARCHAR(255) NOT NULL,
  enabled    SMALLINT NOT NULL DEFAULT 1,
  match_type VARCHAR(3) NOT NULL DEFAULT 'all',
  priority   INTEGER NOT NULL DEFAULT 0,
  conditions TEXT NOT NULL,
  actions    TEXT NOT NULL,
  created    TIMESTAMP DEFAULT now()
);
CREATE INDEX IF NOT EXISTS avuz_filters_user_idx ON avuz_filters (user_id, priority);

CREATE TABLE IF NOT EXISTS avuz_filter_state (
  user_id     INTEGER NOT NULL,
  folder      VARCHAR(255) NOT NULL,
  last_uid    INTEGER NOT NULL DEFAULT 0,
  uidvalidity INTEGER,
  last_run    TIMESTAMP,
  PRIMARY KEY (user_id, folder)
);
