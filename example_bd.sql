-- 1. Create the Database
CREATE DATABASE IF NOT EXISTS example_bd;
USE example_bd;

-- 2. Create the `Url` Table
CREATE TABLE Url (
    id INT PRIMARY KEY AUTO_INCREMENT,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL,
    path VARCHAR(255) NOT NULL,
    query_string VARCHAR(255),
    fragment VARCHAR(255)
);

-- 3. Create the `Page` Table
CREATE TABLE Page (
    id INT PRIMARY KEY AUTO_INCREMENT,
    url_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    H1 VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    p_after_h1 TEXT,
    global_script TEXT,
    canonical_url VARCHAR(255),
    schema_markup TEXT,
    FOREIGN KEY (url_id) REFERENCES Url(id) ON DELETE CASCADE
);

-- 4. Create the `meta_tags` Table
CREATE TABLE meta_tags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    page_id INT NOT NULL,
    meta_title VARCHAR(255) NOT NULL,
    meta_description TEXT,
    viewpoint VARCHAR(255),
    author VARCHAR(255),
    twitter_card_tags VARCHAR(255),
    language_tag VARCHAR(50) NOT NULL,
    FOREIGN KEY (page_id) REFERENCES Page(id) ON DELETE CASCADE,
    UNIQUE (page_id, language_tag)  -- Ensures one meta_tag per page per language
);

-- 5. Create Indexes for Optimization

-- Index on `slug` in `Page` for faster searches and to enforce uniqueness
CREATE UNIQUE INDEX idx_slug ON Page(slug);

-- Index on `language_tag` in `meta_tags` for quicker filtering by language
CREATE INDEX idx_language_tag ON meta_tags(language_tag);
