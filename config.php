<?php

declare(strict_types=1);

const DB_DSN = 'mysql:host=bszw.ddns.net;dbname=wit12a_ITP_StiefScheibl;charset=utf8';
const DB_USER = 'wit12a';
const DB_PASSWORD = 'geheim';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    initializeSchema($pdo);

    return $pdo;
}

function initializeSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS arzt (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            faktor DECIMAL(6,2) DEFAULT 1.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ort (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plz VARCHAR(15) NOT NULL,
            ort VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS patient (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ort_id INT NULL,
            arzt_id INT NOT NULL,
            vorname VARCHAR(120) NOT NULL,
            nachname VARCHAR(120) NOT NULL,
            strasse VARCHAR(120) NOT NULL,
            hausnummer VARCHAR(20) NOT NULL,
            erledigt TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_patient_ort FOREIGN KEY (ort_id) REFERENCES ort(id) ON DELETE SET NULL,
            CONSTRAINT fk_patient_arzt FOREIGN KEY (arzt_id) REFERENCES arzt(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS leistung (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bezeichnung VARCHAR(180) NOT NULL UNIQUE,
            preis DECIMAL(10,2) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS patient_leistung (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            leistung_id INT NOT NULL,
            arzt_id INT NOT NULL,
            datum DATE NOT NULL,
            kostentraeger ENUM("krankenkasse", "selbstzahler") NOT NULL,
            CONSTRAINT fk_pl_patient FOREIGN KEY (patient_id) REFERENCES patient(id) ON DELETE CASCADE,
            CONSTRAINT fk_pl_leistung FOREIGN KEY (leistung_id) REFERENCES leistung(id) ON DELETE RESTRICT,
            CONSTRAINT fk_pl_arzt FOREIGN KEY (arzt_id) REFERENCES arzt(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function sessionUser(): ?array
{
    return $_SESSION['arzt'] ?? null;
}
