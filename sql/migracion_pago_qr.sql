-- =============================================
-- Migración: referencia de pago (N° de operación Yape/Plin/Tarjeta)
-- Ejecutar una sola vez sobre la base fontana_db ya existente.
-- =============================================
USE fontana_db;

ALTER TABLE ventas
    ADD COLUMN referencia_pago VARCHAR(50) NULL AFTER metodo_pago;
