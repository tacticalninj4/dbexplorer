-- DbExplorer PostgreSQL fixture: schema "fixture", shared by the pgsql test
-- group and the workbench seeder (docs/TESTING.md §3.2).
--
-- Run it as a superuser, in one transaction, in a database owned by dbx_admin
-- once the login roles dbx_viewer, dbx_editor and dbx_admin exist. Every
-- object except the extension is owned by dbx_admin. All data is
-- deterministic. Tests treat it as read-only; mutate in a scratch schema.

CREATE SCHEMA fixture AUTHORIZATION dbx_admin;

-- Created before SET ROLE: citext is a trusted extension only from PostgreSQL 13.
CREATE EXTENSION citext WITH SCHEMA fixture;

SET ROLE dbx_admin;

COMMENT ON SCHEMA fixture IS 'DbExplorer test fixture';

-- Types ----------------------------------------------------------------------

CREATE TYPE fixture.mood AS ENUM ('sad', 'ok', 'happy');

CREATE DOMAIN fixture.rating AS smallint CHECK (VALUE BETWEEN 1 AND 5);

CREATE TYPE fixture.address AS (street text, city text, zip text);

CREATE SEQUENCE fixture.ticket_seq START WITH 1000 INCREMENT BY 10;

-- Tables ---------------------------------------------------------------------

-- Masking targets: password and api_token.
CREATE TABLE fixture.users (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email fixture.citext NOT NULL UNIQUE,
    name text NOT NULL,
    password text NOT NULL,
    api_token text,
    mood fixture.mood NOT NULL DEFAULT 'ok',
    address fixture.address,
    created_at timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE fixture.users IS 'People who write posts';
COMMENT ON COLUMN fixture.users.email IS 'Case-insensitive login';
COMMENT ON COLUMN fixture.users.password IS 'Masked by default';

CREATE INDEX users_lower_name_idx ON fixture.users (lower(name));

-- Hidden-object target.
CREATE TABLE fixture.secrets (
    id integer PRIMARY KEY,
    label text NOT NULL,
    value text NOT NULL
);

CREATE TABLE fixture.posts (
    id bigserial PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES fixture.users (id) ON DELETE CASCADE,
    uuid uuid NOT NULL,
    title text NOT NULL,
    body text NOT NULL,
    tags text[] NOT NULL DEFAULT '{}',
    meta jsonb NOT NULL DEFAULT '{}',
    attachment bytea,
    price numeric(12, 2) NOT NULL DEFAULT 0,
    rating fixture.rating,
    published_at timestamptz,
    CONSTRAINT posts_uuid_unique UNIQUE (uuid),
    CONSTRAINT posts_price_check CHECK (price >= 0)
);

COMMENT ON TABLE fixture.posts IS 'Blog posts, one row per post';
COMMENT ON COLUMN fixture.posts.body IS 'Long text, TOASTed for the first post';

CREATE INDEX posts_published_at_idx ON fixture.posts (published_at) WHERE published_at IS NOT NULL;
CREATE INDEX posts_meta_idx ON fixture.posts USING gin (meta);

CREATE TABLE fixture.tags (
    id integer PRIMARY KEY,
    name text NOT NULL UNIQUE
);

-- Composite primary key.
CREATE TABLE fixture.post_tags (
    post_id bigint NOT NULL REFERENCES fixture.posts (id) ON DELETE CASCADE,
    tag_id integer NOT NULL REFERENCES fixture.tags (id),
    PRIMARY KEY (post_id, tag_id)
);

-- No primary key. Also filled by the posts trigger.
CREATE TABLE fixture.event_log (
    event text NOT NULL,
    post_id bigint,
    logged_at timestamptz NOT NULL DEFAULT now()
);

-- Written by sneaky().
CREATE TABLE fixture.counters (
    name text PRIMARY KEY,
    value integer NOT NULL DEFAULT 0
);

CREATE TABLE fixture.measurements (
    id bigint NOT NULL,
    recorded_on date NOT NULL,
    reading numeric(8, 2) NOT NULL,
    PRIMARY KEY (id, recorded_on)
) PARTITION BY RANGE (recorded_on);

CREATE TABLE fixture.measurements_2025 PARTITION OF fixture.measurements
    FOR VALUES FROM ('2025-01-01') TO ('2026-01-01');
CREATE TABLE fixture.measurements_2026 PARTITION OF fixture.measurements
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');

-- 1M rows for pagination, export and performance checks. The key is added after the load.
CREATE TABLE fixture.big (
    id bigint NOT NULL,
    n integer NOT NULL,
    label text NOT NULL,
    created_at timestamptz NOT NULL
);

-- Data -----------------------------------------------------------------------

INSERT INTO fixture.users (email, name, password, api_token, mood, address, created_at) VALUES
    ('Alice@Example.com', 'Alice', '$2y$12$aliceHashAliceHashAliceHashAliceHashAliceHashAliceHa', 'tok_alice_0001', 'happy', ROW('1 Main St', 'Springfield', '12345'), '2026-01-01 09:00:00+00'),
    ('bob@example.com', 'Bob', '$2y$12$bobHashBobHashBobHashBobHashBobHashBobHashBobHashBobH', 'tok_bob_0002', 'ok', ROW('2 Side St', 'Shelbyville', '54321'), '2026-01-02 09:00:00+00'),
    ('carol@example.com', 'Carol', '$2y$12$carolHashCarolHashCarolHashCarolHashCarolHashCarolHa', NULL, 'sad', NULL, '2026-01-03 09:00:00+00');

INSERT INTO fixture.secrets (id, label, value) VALUES
    (1, 'stripe', 'sk_test_fixture_1'),
    (2, 'aws', 'AKIAFIXTURE2');

INSERT INTO fixture.tags (id, name) VALUES (1, 'postgres'), (2, 'laravel'), (3, 'security');

INSERT INTO fixture.posts (user_id, uuid, title, body, tags, meta, attachment, price, rating, published_at)
SELECT
    (g % 3) + 1,
    md5('post-' || g)::uuid,
    'Post ' || g,
    CASE WHEN g = 1 THEN repeat('Lorem ipsum dolor sit amet. ', 2000) ELSE 'Body of post ' || g END,
    ARRAY['tag' || (g % 3), 'all'],
    jsonb_build_object('n', g, 'even', g % 2 = 0, 'nested', jsonb_build_object('k', 'v' || g)),
    CASE WHEN g % 5 = 0 THEN decode(md5('file-' || g), 'hex') END,
    (g * 1.25)::numeric(12, 2),
    (g % 5) + 1,
    CASE WHEN g % 4 = 0 THEN NULL ELSE timestamptz '2026-02-01 12:00:00+00' + g * interval '1 day' END
FROM generate_series(1, 50) AS g;

INSERT INTO fixture.post_tags (post_id, tag_id)
SELECT p.id, t.id FROM fixture.posts p JOIN fixture.tags t ON (p.id + t.id) % 2 = 0;

INSERT INTO fixture.event_log (event, post_id, logged_at)
SELECT 'seeded', g, timestamptz '2026-01-01 00:00:00+00' + g * interval '1 minute'
FROM generate_series(1, 10) AS g;

INSERT INTO fixture.counters (name, value) VALUES ('sneaky', 0), ('visits', 42);

INSERT INTO fixture.measurements (id, recorded_on, reading)
SELECT g, date '2025-01-01' + g - 1, ((g * 37) % 1000) / 10.0
FROM generate_series(1, 730) AS g;

INSERT INTO fixture.big (id, n, label, created_at)
SELECT g, g % 1000, 'row ' || g, timestamptz '2026-01-01 00:00:00+00' + g * interval '1 second'
FROM generate_series(1, 1000000) AS g;

ALTER TABLE fixture.big ADD PRIMARY KEY (id);
CREATE INDEX big_n_idx ON fixture.big (n);

-- Views ----------------------------------------------------------------------

CREATE VIEW fixture.published_posts AS
SELECT p.id, p.title, u.name AS author, p.published_at
FROM fixture.posts p
JOIN fixture.users u ON u.id = p.user_id
WHERE p.published_at IS NOT NULL;

COMMENT ON VIEW fixture.published_posts IS 'Posts with a publish date';

-- Reads the hidden table, for the hidden-object checks.
CREATE VIEW fixture.secret_labels AS
SELECT id, label FROM fixture.secrets;

CREATE MATERIALIZED VIEW fixture.post_stats AS
SELECT user_id, count(*) AS posts, sum(price) AS total_price
FROM fixture.posts
GROUP BY user_id
WITH DATA;

-- Functions and trigger ------------------------------------------------------

CREATE FUNCTION fixture.post_count(p_user_id bigint) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$ SELECT count(*) FROM fixture.posts WHERE user_id = p_user_id $$;

CREATE FUNCTION fixture.slugify(p_title text) RETURNS text
    LANGUAGE plpgsql IMMUTABLE
    AS $$
BEGIN
    RETURN trim(BOTH '-' FROM regexp_replace(lower(p_title), '[^a-z0-9]+', '-', 'g'));
END
$$;

-- Looks like a read, but writes: SELECT fixture.sneaky() must fail for viewers.
CREATE FUNCTION fixture.sneaky() RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    v integer;
BEGIN
    UPDATE fixture.counters SET value = value + 1 WHERE name = 'sneaky' RETURNING value INTO v;
    RETURN v;
END
$$;

CREATE FUNCTION fixture.log_post_change() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        INSERT INTO fixture.event_log (event, post_id) VALUES ('delete', OLD.id);
    ELSE
        INSERT INTO fixture.event_log (event, post_id) VALUES (lower(TG_OP), NEW.id);
    END IF;

    RETURN NULL;
END
$$;

-- Created after the data load, so the seeded rows stay deterministic.
CREATE TRIGGER posts_log_change
    AFTER INSERT OR UPDATE OR DELETE ON fixture.posts
    FOR EACH ROW EXECUTE FUNCTION fixture.log_post_change();

-- Grants (docs/TESTING.md §3.2 item 4) ---------------------------------------

GRANT USAGE ON SCHEMA fixture TO dbx_viewer, dbx_editor;
GRANT SELECT ON ALL TABLES IN SCHEMA fixture TO dbx_viewer;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA fixture TO dbx_viewer;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA fixture TO dbx_editor;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA fixture TO dbx_editor;

ANALYZE fixture.users, fixture.posts, fixture.big, fixture.measurements;

RESET ROLE;
