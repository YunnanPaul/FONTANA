-- Ejecutar una sola vez si deseas actualizar la base de datos manualmente.
-- La aplicación también intenta agregar estas columnas automáticamente.
USE fontana_db;

ALTER TABLE ventas
    ADD COLUMN tipo_comprobante ENUM('boleta','factura') NOT NULL DEFAULT 'boleta' AFTER metodo_pago,
    ADD COLUMN tipo_documento ENUM('SIN_DOCUMENTO','DNI','RUC') NOT NULL DEFAULT 'SIN_DOCUMENTO' AFTER tipo_comprobante,
    ADD COLUMN numero_documento VARCHAR(20) NULL AFTER tipo_documento,
    ADD COLUMN cliente_nombre VARCHAR(180) NOT NULL DEFAULT 'CONSUMIDOR FINAL' AFTER numero_documento,
    ADD COLUMN cliente_direccion VARCHAR(255) NULL AFTER cliente_nombre,
    ADD COLUMN documento_consultado TINYINT(1) NOT NULL DEFAULT 0 AFTER cliente_direccion,
    ADD COLUMN api_proveedor VARCHAR(50) NULL AFTER documento_consultado;

CREATE INDEX idx_ventas_documento ON ventas (tipo_documento, numero_documento);
