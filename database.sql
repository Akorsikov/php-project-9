
-- -- Создать БД 'websites_db';
-- DO $$
-- BEGIN
--     IF NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'websites_db_trjx') THEN
--         EXECUTE 'CREATE DATABASE websites_db';
--     ENDIF;
-- END $$;

-- CREATE USER aleksandr WITH ENCRYPTED PASSWORD '123456';

-- Удалить таблицы 'urls, url_checks' если существют
DROP TABLE IF EXISTS urls, url_checks CASCADE;

-- Создать таблицу 'urls'
CREATE TABLE urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255),
    created_at TIMESTAMP(0) DEFAULT NOW()
);

-- Создать таблицу 'url_checks'
CREATE TABLE url_checks (
    id SERIAL PRIMARY KEY,
    url_id INTEGER NOT NULL,
    status_code INТ NOT NULL,
    h1 VARCHAR(255),
    title VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP(0) DEFAULT NOW()
);

-- 
GRANT ALL PRIVILEGES ON urls, url_checks TO aleksandr;

--
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO aleksandr;