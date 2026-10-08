-- Solo esquema original para PRUEBAS sobre una base desechable. Sin DROP, USE ni datos.
CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    clave VARCHAR(255) NOT NULL,
    rol ENUM('administrador', 'cajero') NOT NULL DEFAULT 'administrador',
    estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sesiones_activas (
    id_sesion INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    session_token VARCHAR(128) NOT NULL UNIQUE,
    ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45) NULL,
    CONSTRAINT fk_sesion_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ventas (
    id_venta INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    metodo_pago VARCHAR(30) NOT NULL DEFAULT 'Efectivo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS detalle_venta (
    id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_venta INT NOT NULL,
    id_producto INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_detalle_venta FOREIGN KEY (id_venta) REFERENCES ventas(id_venta),
    CONSTRAINT fk_detalle_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    tipo_movimiento ENUM('entrada', 'salida', 'ajuste') NOT NULL,
    cantidad INT NOT NULL,
    motivo VARCHAR(160) NULL,
    stock_anterior INT NOT NULL DEFAULT 0,
    stock_nuevo INT NOT NULL DEFAULT 0,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movimiento_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cierres_caja (
    id_cierre INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    monto_inicial DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_ventas DECIMAL(10,2) NOT NULL DEFAULT 0,
    monto_final DECIMAL(10,2) NOT NULL DEFAULT 0,
    observacion TEXT NULL,
    estado ENUM('abierta', 'cerrada') NOT NULL DEFAULT 'abierta',

    id_usuario_apertura INT NULL,
    nombre_usuario_apertura VARCHAR(120) NULL,
    id_usuario_cierre INT NULL,
    nombre_usuario_cierre VARCHAR(120) NULL,
    CONSTRAINT fk_caja_apertura FOREIGN KEY (id_usuario_apertura) REFERENCES usuarios(id_usuario),
    CONSTRAINT fk_caja_cierre FOREIGN KEY (id_usuario_cierre) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    CONSTRAINT fk_repos_aprueba FOREIGN KEY (id_usuario_aprueba) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;