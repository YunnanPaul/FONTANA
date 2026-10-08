-- FONTANA | INSTALACIÓN NUEVA NO DESTRUCTIVA
-- Selecciona primero una base VACÍA en phpMyAdmin (p. ej. fontana_db).
-- Este archivo NO cambia de base, NO elimina tablas y NO modifica registros existentes.
-- Para ACTUALIZAR, conserva tu base y usa configurar_pagos.php, con respaldo previo.
-- Repetir este archivo no duplica semillas. No repara tablas preexistentes incompletas.
-- Requiere MySQL/MariaDB y utf8mb4; copia de pruebas recomendada.
SET NAMES utf8mb4;
SET @fontana_base_vacia := (SELECT COUNT(*)=0 FROM information_schema.tables WHERE table_schema=DATABASE());

CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    clave VARCHAR(255) NOT NULL,
    rol ENUM('administrador', 'cajero') NOT NULL DEFAULT 'administrador',
    estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuarios_usuario_estado (usuario, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sesiones_activas (
    id_sesion INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    session_token VARCHAR(128) NOT NULL UNIQUE,
    ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45) NULL,
    CONSTRAINT fk_sesion_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario),
    INDEX idx_sesiones_token (session_token),
    INDEX idx_sesiones_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS productos (
    id_producto INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    descripcion TEXT NULL,
    tipo ENUM('producto', 'insumo') NOT NULL DEFAULT 'producto',
    categoria VARCHAR(60) NOT NULL DEFAULT 'general',
    precio DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 0,
    unidad_medida VARCHAR(40) NOT NULL DEFAULT 'unidad',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_productos_tipo_nombre (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ventas (
    id_venta INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    metodo_pago VARCHAR(30) NOT NULL DEFAULT 'Efectivo',
    referencia_pago VARCHAR(50) NULL,
    tipo_comprobante ENUM('boleta','factura') NOT NULL DEFAULT 'boleta',
    tipo_documento ENUM('SIN_DOCUMENTO','DNI','RUC') NOT NULL DEFAULT 'SIN_DOCUMENTO',
    numero_documento VARCHAR(20) NULL,
    cliente_nombre VARCHAR(180) NOT NULL DEFAULT 'CONSUMIDOR FINAL',
    cliente_direccion VARCHAR(255) NULL,
    documento_consultado TINYINT(1) NOT NULL DEFAULT 0,
    api_proveedor VARCHAR(50) NULL,
    INDEX idx_ventas_documento (tipo_documento,numero_documento),
    INDEX idx_ventas_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS detalle_venta (
    id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_venta INT NOT NULL,
    id_producto INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_detalle_venta FOREIGN KEY (id_venta) REFERENCES ventas(id_venta),
    CONSTRAINT fk_detalle_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto),
    INDEX idx_detalle_venta_rel (id_venta, id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    tipo_movimiento ENUM('entrada', 'salida', 'ajuste') NOT NULL,
    cantidad INT NOT NULL,
    motivo VARCHAR(160) NULL,
    stock_anterior INT NOT NULL DEFAULT 0,
    stock_nuevo INT NOT NULL DEFAULT 0,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movimiento_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto),
    INDEX idx_movimientos_producto_fecha (id_producto, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cierres_caja (
    id_cierre INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    monto_inicial DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_ventas DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_efectivo DECIMAL(10,2) NULL,
    monto_final DECIMAL(10,2) NOT NULL DEFAULT 0,
    observacion TEXT NULL,
    estado ENUM('abierta', 'cerrada') NOT NULL DEFAULT 'abierta',

    id_usuario_apertura INT NULL,
    nombre_usuario_apertura VARCHAR(120) NULL,
    id_usuario_cierre INT NULL,
    nombre_usuario_cierre VARCHAR(120) NULL,
    CONSTRAINT fk_caja_apertura FOREIGN KEY (id_usuario_apertura) REFERENCES usuarios(id_usuario),
    CONSTRAINT fk_caja_cierre FOREIGN KEY (id_usuario_cierre) REFERENCES usuarios(id_usuario),
    INDEX idx_cierres_fecha_estado (fecha, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS solicitudes_reposicion (
    id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    cantidad_solicitada INT NOT NULL,
    observacion TEXT NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    nota_admin VARCHAR(255) NULL,
    id_usuario_solicita INT NOT NULL,
    id_usuario_aprueba INT NULL,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_respuesta DATETIME NULL,
    CONSTRAINT fk_repos_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto),
    CONSTRAINT fk_repos_solicita FOREIGN KEY (id_usuario_solicita) REFERENCES usuarios(id_usuario),
    CONSTRAINT fk_repos_aprueba FOREIGN KEY (id_usuario_aprueba) REFERENCES usuarios(id_usuario),
    INDEX idx_reposicion_estado (estado),
    INDEX idx_reposicion_producto (id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos iniciales usuarios: solo para una base completamente vacía al iniciar.
INSERT INTO usuarios (nombre, usuario, clave, rol, estado) SELECT 'Administrador Fontana', 'admin', '$2y$12$Jj26jdHwdXZHHhBEiLQAO.I3gJDOkuJsHLPUS/aWgfLx6TXkkZYju', 'administrador', 'activo' WHERE @fontana_base_vacia=1;
INSERT INTO usuarios (nombre, usuario, clave, rol, estado) SELECT 'Caja Fontana', 'caja', '$2y$12$GxiIfid50Y7FiG6I210Alu9Q/VBVcyDEVvi/F953ymLdL/VgTHJ9q', 'cajero', 'activo' WHERE @fontana_base_vacia=1;

-- Datos iniciales productos: solo para una base completamente vacía al iniciar.
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Cono Warma',         'Helado en cono pequeño',          'helados',  'producto', 2.50,  50, 10, 'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Cono Misky',         'Helado en cono mediano',          'helados',  'producto', 3.00,  50, 10, 'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Cono Inti',          'Helado en cono grande',           'helados',  'producto', 6.00,  40, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Cono Killa',         'Helado en cono especial',         'helados',  'producto', 6.00,  40, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Vaso Inti',          'Helado en vaso grande',           'helados',  'producto', 6.00,  40, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Vaso Killa',         'Helado en vaso especial',         'helados',  'producto', 6.50,  40, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Vaso Wari',          'Helado en vaso premium',          'helados',  'producto', 8.00,  30, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Vaso Usnu',          'Helado en vaso premium',          'helados',  'producto', 8.00,  30, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Vaso Princesa',      'Helado en vaso de lujo',          'helados',  'producto', 10.00, 20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Copa Killa',         'Helado en copa especial',         'helados',  'producto', 12.00, 20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Copa Inti',          'Helado en copa especial',         'helados',  'producto', 12.00, 20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Leche',              'Sabor base de leche',             'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Café',               'Sabor a café',                    'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Fresa',              'Sabor a fresa',                   'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Chocolate',          'Sabor a chocolate',               'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Coco',               'Sabor a coco',                    'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Lúcuma',             'Sabor a lúcuma',                  'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Maracuyá',           'Sabor a maracuyá',                'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Ron con Pasas',      'Sabor a ron con pasas',           'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Chocochip',          'Sabor a chocochip',               'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Menta',              'Sabor a menta',                   'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Mango',              'Sabor a mango',                   'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Chirimoya',          'Sabor a chirimoya',               'sabores',  'insumo',   0.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Leche asada',        'Postre de leche asada',           'postres',  'producto', 3.00,  15, 3,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Keke de Fresa',      'Keke de fresa',                   'postres',  'producto', 2.00,  20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Keke de Naranja',    'Keke de naranja',                 'postres',  'producto', 2.00,  20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Torta Chocolate',    'Torta de chocolate',              'postres',  'producto', 7.00,  10, 2,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Selva Negra',        'Torta selva negra',               'postres',  'producto', 7.00,  10, 2,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Tartaleta Fresa',    'Tartaleta de fresa',              'postres',  'producto', 6.50,  12, 3,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Tartaleta Mango',    'Tartaleta de mango',              'postres',  'producto', 6.50,  12, 3,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Tartaleta Durazno',  'Tartaleta de durazno',            'postres',  'producto', 6.50,  12, 3,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Pie de Manzana',     'Pie de manzana',                  'postres',  'producto', 7.00,  10, 2,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Empanadas',          'Empanadas',                       'postres',  'producto', 3.00,  20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Milhojas',           'Milhojas',                        'postres',  'producto', 7.00,  10, 2,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Yogurt de Fresa',    'Yogurt de fresa (500ml)',         'insumos',  'insumo',   8.00,  20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Yogurt de Lúcuma',   'Yogurt de lúcuma (500ml)',        'insumos',  'insumo',   8.00,  20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Queso fresco',       'Queso fresco',                    'insumos',  'insumo',   26.00, 10, 2,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Mantequilla',        'Mantequilla',                     'insumos',  'insumo',   6.00,  15, 3,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Snack de Quinua',    'Snack de quinua',                 'snacks',   'producto', 2.00,  30, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Snack de Soya',      'Snack de soya',                   'snacks',   'producto', 2.00,  30, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Snack de Papitas',   'Snack de papitas',                'snacks',   'producto', 2.00,  30, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Papitas Picante',    'Papitas picantes',                'snacks',   'producto', 2.00,  30, 8,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Café Americano',     'Café americano caliente',         'bebidas',  'producto', 4.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Café Pasado',        'Café pasado tradicional',         'bebidas',  'producto', 4.00,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Té de Manzanilla',   'Té de manzanilla',                'bebidas',  'producto', 3.50,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Té de Anis',         'Té de anís',                      'bebidas',  'producto', 3.50,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Té Verde',           'Té verde',                        'bebidas',  'producto', 3.50,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Té Canela y Clavo',  'Té de canela y clavo',            'bebidas',  'producto', 3.50,  0,  0,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Agua Cielo',         'Agua Cielo 600ml',                'bebidas',  'producto', 1.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Agua San Luis',      'Agua San Luis 600ml',             'bebidas',  'producto', 2.00,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Agua San Mateo',     'Agua San Mateo 600ml',            'bebidas',  'producto', 2.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Agua San Mateo Gas', 'Agua San Mateo con gas 600ml',    'bebidas',  'producto', 2.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Free Tea',           'Bebida Free Tea',                 'bebidas',  'producto', 3.50,  20, 5,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Coca Cola',          'Coca Cola 500ml',                 'bebidas',  'producto', 3.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Inka Cola',          'Inka Cola 500ml',                 'bebidas',  'producto', 3.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Fanta Original',     'Fanta Original 500ml',            'bebidas',  'producto', 3.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Fanta Cola inglesa', 'Fanta Cola inglesa 500ml',        'bebidas',  'producto', 3.50,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Sporade Tropical',   'Sporade Tropical 500ml',          'bebidas',  'producto', 3.00,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Sporade Mandarina',  'Sporade Mandarina 500ml',         'bebidas',  'producto', 3.00,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;
INSERT INTO productos (nombre, descripcion, categoria, tipo, precio, stock, stock_minimo, unidad_medida) SELECT 'Sporade Uva',        'Sporade Uva 500ml',               'bebidas',  'producto', 3.00,  24, 6,  'unidad' WHERE @fontana_base_vacia=1;

