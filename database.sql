
-- -- Создать БД 'websites_db';
-- CREATE DATABASE websites_db;
-- create user aleksandr with encrypted password '123456';

-- Удалить таблицу 'urls' если существует
DROP TABLE IF EXISTS urls CASCADE;

-- Создать таблицу 'urls'
CREATE TABLE urls (
    id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name VARCHAR(255),
    created_at TIMESTAMP
);