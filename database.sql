
-- -- Создать БД 'websites_db';
-- CREATE DATABASE websites_db;
-- CREATE USER aleksandr WITH ENCRYPTED PASSWORD '123456';

-- Удалить таблицу 'urls' если существует
DROP TABLE IF EXISTS urls CASCADE;

-- Создать таблицу 'urls'
CREATE TABLE urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255),
    created_at TIMESTAMP(0) DEFAULT NOW()
);

-- 
GRANT ALL PRIVILEGES ON urls TO aleksandr;

--
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO aleksandr;