-- Удалить таблицы 'urls, url_checks' если существют
-- DROP TABLE IF EXISTS urls, url_checks CASCADE;

-- Создать таблицу 'urls'
-- CREATE TABLE urls (

-- Создать таблицу 'urls' если не существует
CREATE TABLE IF NOT EXISTS urls (
    id SERIAL PRIMARY KEY,
    name varchar(255) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT LOCALTIMESTAMP(0) NOT NULL
);

-- Создать таблицу 'url_checks'
-- CREATE TABLE url_checks (

-- Создать таблицу 'url_checks' если не существует
CREATE TABLE IF NOT EXISTS url_checks (
    id SERIAL PRIMARY KEY,
    url_id integer REFERENCES urls(id) NOT NULL,
    status_code integer,
    h1 varchar(255),
    title varchar(255),
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT LOCALTIMESTAMP(0) NOT NULL
);
