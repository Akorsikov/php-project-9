-- Удалить таблицы 'urls, url_checks' если существют
DROP TABLE IF EXISTS urls, url_checks CASCADE;

-- Создать таблицу 'urls'
CREATE TABLE urls (
    id SERIAL PRIMARY KEY,
    name varchar(255),
    created_at TIMESTAMP(0) DEFAULT NOW()
);

-- Создать таблицу 'url_checks'
CREATE TABLE url_checks (
    id SERIAL PRIMARY KEY,
    url_id integer REFERENCES urls(id) NOT NULL,
    status_code integer,
    h1 varchar(255),
    title varchar(255),
    description TEXT,
    created_at TIMESTAMP(0) DEFAULT NOW()
);
