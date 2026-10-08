-- FONTANA · ampliación NO destructiva. Respalde la base antes de instalar.
-- Seleccione su base existente en phpMyAdmin. NO importe fontana_db.sql al actualizar.
-- El instalador configurar_pagos.php añade también cierres_caja.total_efectivo
-- de manera condicional. No se alteran las ventas históricas.
CREATE TABLE IF NOT EXISTS pagos_ordenes (
  id_orden BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  publico CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  idempotencia CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  id_usuario INT NOT NULL,
  id_cierre INT NOT NULL,
  total_centimos BIGINT NOT NULL,
  moneda CHAR(3) NOT NULL DEFAULT 'PEN',
  metodo VARCHAR(30) NOT NULL,
  proveedor VARCHAR(20) NOT NULL,
  entorno VARCHAR(20) NOT NULL,
  merchant_code VARCHAR(30) NOT NULL DEFAULT '',
  numero_externo VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  transaccion VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  instante_ms VARCHAR(16) NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  comprobante_json TEXT NOT NULL,
  referencia VARCHAR(100) NULL,
  autorizacion VARCHAR(100) NULL,
  metodo_proveedor VARCHAR(30) NULL,
  pago_verificado TINYINT(1) NOT NULL DEFAULT 0,
  alerta TEXT NULL,
  token_sesion TEXT NULL,
  token_creado DATETIME NULL,
  billing_json TEXT NULL,
  recibido_centimos BIGINT NULL,
  vuelto_centimos BIGINT NULL,
  id_venta INT NULL UNIQUE,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  reserva_hasta DATETIME NOT NULL,
  INDEX idx_pagos_estado(estado, reserva_hasta),
  INDEX idx_pagos_usuario(id_usuario, creado),
  INDEX idx_pagos_caja(id_cierre, estado),
  UNIQUE KEY uq_pago_autorizacion(entorno, merchant_code, autorizacion),
  CONSTRAINT fk_po_usuario FOREIGN KEY(id_usuario) REFERENCES usuarios(id_usuario),
  CONSTRAINT fk_po_caja FOREIGN KEY(id_cierre) REFERENCES cierres_caja(id_cierre),
  CONSTRAINT fk_po_venta FOREIGN KEY(id_venta) REFERENCES ventas(id_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos_lineas (
  id_linea BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_orden BIGINT UNSIGNED NOT NULL,
  id_producto INT NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  cantidad INT NOT NULL,
  precio_centimos BIGINT NOT NULL,
  subtotal_centimos BIGINT NOT NULL,
  UNIQUE KEY uq_pago_producto(id_orden, id_producto),
  INDEX idx_pl_producto(id_producto),
  CONSTRAINT fk_pl_orden FOREIGN KEY(id_orden) REFERENCES pagos_ordenes(id_orden),
  CONSTRAINT fk_pl_producto FOREIGN KEY(id_producto) REFERENCES productos(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos_eventos (
  id_evento BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_orden BIGINT UNSIGNED NOT NULL,
  huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  tipo VARCHAR(50) NOT NULL,
  nota VARCHAR(500) NOT NULL,
  id_usuario INT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pe_orden(id_orden, creado),
  CONSTRAINT fk_pe_orden FOREIGN KEY(id_orden) REFERENCES pagos_ordenes(id_orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
