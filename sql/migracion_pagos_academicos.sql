-- FONTANA: ampliación académica ADITIVA. Ejecutar después de migracion_pagos_api.sql.
-- No elimina ni modifica ventas históricas. Repetible.
CREATE TABLE IF NOT EXISTS pagos_demo (
    id_orden BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    expira DATETIME NOT NULL,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pd_orden FOREIGN KEY(id_orden) REFERENCES pagos_ordenes(id_orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pagos_mercadopago (
    id_orden BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    idempotencia VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pago_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    estado VARCHAR(40) NOT NULL DEFAULT 'enviado',
    detalle VARCHAR(120) NULL,
    consultado DATETIME NULL,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pm_orden FOREIGN KEY(id_orden) REFERENCES pagos_ordenes(id_orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
