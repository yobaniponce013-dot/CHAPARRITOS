<?php
/* ========================================================
   SERVIDOR API PHP NATIVO - CHAPARRITOS PIZZA
   ======================================================== */

// Configuración de cabeceras CORS para permitir peticiones desde cualquier host/móvil
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, DELETE");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Responder de inmediato a peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Configuración de la base de datos MySQL (dinámica para Railway o XAMPP local)
$host = getenv('MYSQLHOST') ?: '127.0.0.1';
$db   = getenv('MYSQLDATABASE') ?: 'chaparritos';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '';
$port = getenv('MYSQLPORT') ?: 3306;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;port=$port;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Sembrar catálogo premium expandido si es necesario
    checkAndSeedDatabase($pdo);
} catch (\PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos: ' . $e->getMessage() . '. Verifique que MySQL en XAMPP esté ENCENDIDO y que la base de datos "chaparritos" haya sido creada correctamente.'
    ]);
    exit;
}

function createTablesIfNotExist($pdo) {
    // Verificar si la tabla 'users' existe. Si no, asumimos que la base está completamente en blanco
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->fetch()) {
        return; // Las tablas ya existen, no hay nada que crear
    }

    // SQL de creación de todas las tablas relacionales para Chaparritos Pizza
    $sql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        phone VARCHAR(15) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('client', 'admin') DEFAULT 'client',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        category ENUM('pizzas', 'drinks', 'sides', 'promos') NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        sizes VARCHAR(100) DEFAULT 'Chica,Mediana,Familiar',
        ingredients TEXT,
        is_available BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNIQUE,
        stock_quantity INT NOT NULL DEFAULT 50,
        min_stock INT NOT NULL DEFAULT 5,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS offers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        discount_percent INT NOT NULL CHECK (discount_percent > 0 AND discount_percent <= 100),
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        guest_name VARCHAR(100) NULL,
        guest_phone VARCHAR(15) NULL,
        order_type ENUM('pickup', 'delivery') NOT NULL,
        delivery_address TEXT NULL,
        delivery_lat DECIMAL(10, 8) NULL,
        delivery_lng DECIMAL(11, 8) NULL,
        delivery_distance DECIMAL(5, 2) NULL,
        estimated_time VARCHAR(50) NOT NULL,
        status ENUM('preparando', 'horno', 'listo', 'entregado') DEFAULT 'preparando',
        subtotal DECIMAL(10, 2) NOT NULL,
        discount_amount DECIMAL(10, 2) DEFAULT 0.00,
        total_amount DECIMAL(10, 2) NOT NULL,
        promo_code VARCHAR(20) NULL,
        payment_method ENUM('cash', 'card') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NULL,
        product_name VARCHAR(100) NOT NULL,
        size VARCHAR(20) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10, 2) NOT NULL,
        extra_ingredients TEXT NULL,
        total_price DECIMAL(10, 2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        card_brand VARCHAR(20) NULL,
        last4 VARCHAR(4) NULL,
        transaction_status VARCHAR(50) NOT NULL DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;
    ";

    $pdo->exec($sql);
}

// Función para sembrar catálogo de productos automáticamente si es necesario
function checkAndSeedDatabase($pdo) {
    try {
        // Crear tablas si no existen
        createTablesIfNotExist($pdo);

        // Verificar si la base de datos tiene productos
        $stmt = $pdo->query("SELECT COUNT(*) FROM products");
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            // Solución de una sola vez para productos existentes con imágenes erróneas
            $pdo->query("UPDATE products SET image_url = 'https://images.unsplash.com/photo-1585503415907-88922237c4fc?w=600&auto=format&fit=crop&q=60' WHERE name = 'Dedos de Queso Mozzarella' AND image_url LIKE '%photo-1531749668029-2db88e4b76ce%'");
            $pdo->query("UPDATE products SET image_url = 'https://images.unsplash.com/photo-1523362628745-0c100150b504?w=600&auto=format&fit=crop&q=60' WHERE name = 'Agua Embotellada Ciel 1L' AND image_url LIKE '%photo-1608885898957-a599fb1b467a%'");
            $pdo->query("UPDATE products SET image_url = 'https://images.unsplash.com/photo-1544982503-9f984c14501a?w=600&auto=format&fit=crop&q=60' WHERE name = 'Pan de Ajo Supremo' AND image_url LIKE '%photo-1573140247632-f8fd74997d5c%'");

            // Sembrar usuario administrador por defecto si no existe
            $stmtAdmin = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmtAdmin->execute(['admin@chaparritos.com']);
            if (!$stmtAdmin->fetch()) {
                $stmtInsertAdmin = $pdo->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
                $stmtInsertAdmin->execute([
                    'Administrador Chaparritos',
                    'admin@chaparritos.com',
                    '5551234567',
                    'admin',
                    'admin'
                ]);
            }

            // Sembrar usuario cliente por defecto si no existe
            $stmtClient = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmtClient->execute(['cliente@chaparritos.com']);
            if (!$stmtClient->fetch()) {
                $stmtInsertClient = $pdo->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
                $stmtInsertClient->execute([
                    'Josue Ponce (Cliente)',
                    'cliente@chaparritos.com',
                    '5559876543',
                    'cliente',
                    'client'
                ]);
            }

            return; // Retornar temprano para evitar que se resiembren productos eliminados o se sobreescriban ediciones
        }

        // Catálogo premium expandido (27 productos)
        $seedProducts = [
            // PIZZAS
            [
                'name' => 'Pizza Chaparrita Especial',
                'description' => 'Nuestra pizza insignia con jamón premium, pepperoni crujiente, champiñones frescos, cebolla morada y pimientos.',
                'price' => 189.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Jamón,Pepperoni,Champiñones,Cebolla,Pimiento',
                'stock' => 50
            ],
            [
                'name' => 'Mexicana Volcán',
                'description' => 'Una explosión picante de sabor con chorizo premium, carne molida especiada, jalapeños en rodajas y frijoles.',
                'price' => 199.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1590947132387-155cc02f3212?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Chorizo,Carne Molida,Jalapeños,Frijoles',
                'stock' => 45
            ],
            [
                'name' => 'Hawaiana Premium',
                'description' => 'La favorita de muchos. Doble porción de jamón de pierna y piña miel fresca seleccionada sobre salsa pomodoro.',
                'price' => 179.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Doble Jamón,Doble Piña',
                'stock' => 50
            ],
            [
                'name' => 'Carnívora Extrema',
                'description' => 'Para amantes de la carne. Combinación perfecta de jamón, pepperoni crujiente, tocino ahumado, chorizo y carne molida.',
                'price' => 219.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1534308983496-4fabb1a015ee?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Jamón,Pepperoni,Tocino,Chorizo,Carne Molida',
                'stock' => 40
            ],
            [
                'name' => 'Poblana de Lujo',
                'description' => 'Fusión mexicana exquisita. Tiras de pollo a la parrilla, rajas de chile poblano, elote tierno y queso crema cremoso.',
                'price' => 209.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1571407970349-bc81e7e96d47?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Pollo Parrilla,Rajas Poblanas,Elote,Queso Crema',
                'stock' => 35
            ],
            [
                'name' => 'Cuatro Quesos Gourmet',
                'description' => 'Una mezcla celestial para paladares refinados: queso mozzarella, gorgonzola curado, parmesano rallado y queso de cabra suave.',
                'price' => 229.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1593560708920-61dd98c46a4e?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Mozzarella,Gorgonzola,Parmesano,Queso de Cabra',
                'stock' => 30
            ],
            [
                'name' => 'BBQ Chicken & Bacon',
                'description' => 'Jugosas tiras de pollo y tocino crujiente, bañados en nuestra salsa BBQ ahumada artesanal con un toque de cebolla morada.',
                'price' => 209.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1565299585323-38d6b0865b47?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Pollo,Tocino,Salsa BBQ,Cebolla Morada',
                'stock' => 38
            ],
            [
                'name' => 'Vegetariana Silvestre',
                'description' => 'Una opción fresca e ideal. Champiñones fileteados, pimiento verde, cebolla morada, aceitunas negras y jitomate cherry.',
                'price' => 189.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Champiñones,Pimiento,Cebolla,Aceitunas,Jitomate Cherry',
                'stock' => 45
            ],
            [
                'name' => 'Margarita Rústica',
                'description' => 'Sencilla pero extraordinaria. Rodajas de jitomate fresco, doble porción de queso mozzarella, albahaca fresca y aceite de oliva.',
                'price' => 179.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1604068549290-dea0e4a305ca?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Doble Queso Mozzarella,Rodajas de Jitomate,Albahaca Fresca,Aceite de Oliva',
                'stock' => 50
            ],
            [
                'name' => 'Pizza Pastorera',
                'description' => 'El sabor de la taquería en tu pizza. Carne al pastor marinada, piña asada, cebolla fresca, cilantro y salsa picante aparte.',
                'price' => 219.00,
                'category' => 'pizzas',
                'image_url' => 'https://images.unsplash.com/photo-1594007654729-407ededc4963?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Chica,Mediana,Familiar',
                'ingredients' => 'Queso Mozzarella,Carne al Pastor,Piña,Cebolla,Cilantro',
                'stock' => 42
            ],

            // SIDES (COMPLEMENTOS)
            [
                'name' => 'Papas en Gajo Especiadas',
                'description' => 'Gajos de papa sazonados con especias de la casa. Crujientes por fuera y suaves por dentro. Con aderezo ranch.',
                'price' => 79.00,
                'category' => 'sides',
                'image_url' => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Papas,Sazonador,Aderezo Ranch',
                'stock' => 30
            ],
            [
                'name' => 'Alitas BBQ Glaseadas',
                'description' => '10 jugosas alitas de pollo horneadas a la perfección y bañadas en salsa BBQ ahumada.',
                'price' => 139.00,
                'category' => 'sides',
                'image_url' => 'https://images.unsplash.com/photo-1567620832903-9fc6debc209f?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Alitas,Salsa BBQ',
                'stock' => 25
            ],
            [
                'name' => 'Alitas Fuego Habanero',
                'description' => '10 alitas crujientes bañadas en una intensa y deliciosa salsa habanera con un toque de miel.',
                'price' => 139.00,
                'category' => 'sides',
                'image_url' => 'https://images.unsplash.com/photo-1608039829572-78524f79c4c7?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Alitas,Salsa Habanero',
                'stock' => 20
            ],
            [
                'name' => 'Aros de Cebolla Crujientes',
                'description' => 'Porción generosa de aros de cebolla empanizados y fritos al punto de oro. Acompañados de aderezo chipotle.',
                'price' => 69.00,
                'category' => 'sides',
                'image_url' => 'https://images.unsplash.com/photo-1639024471283-2bc7b3c6a267?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Aros de Cebolla,Aderezo Chipotle',
                'stock' => 30
            ],
            [
                'name' => 'Dedos de Queso Mozzarella',
                'description' => '6 deliciosos dedos de queso mozzarella empanizados con hierbas italianas, derretidos por dentro. Servidos con salsa pomodoro.',
                'price' => 89.00,
                'category' => 'sides',
                'image_url' => 'https://images.unsplash.com/photo-1585503415907-88922237c4fc?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Queso Mozzarella,Empanizado Hierbas,Salsa Pomodoro',
                'stock' => 35
            ],
            [
                'name' => 'Pan de Ajo Supremo',
                'description' => '4 rebanadas de pan horneado con mantequilla de ajo artesanal, especias y una gruesa capa de queso fundido.',
                'price' => 59.00,
                'category' => 'sides',
                'image_url' => 'https://images.unsplash.com/photo-1544982503-9f984c14501a?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Pan,Mantequilla de Ajo,Queso Mozzarella',
                'stock' => 40
            ],

            // DRINKS (REFRESCOS)
            [
                'name' => 'Refresco Coca-Cola 600ml',
                'description' => 'Refresco de cola helado en botella de plástico de 600ml.',
                'price' => 28.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?w=600&auto=format&fit=crop&q=60',
                'sizes' => '600ml',
                'ingredients' => 'Coca-Cola',
                'stock' => 80
            ],
            [
                'name' => 'Refresco Coca-Cola Sin Azúcar 600ml',
                'description' => 'El sabor clásico de Coca-Cola pero sin calorías. Botella de 600ml.',
                'price' => 28.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1554866585-cd94860890b7?w=600&auto=format&fit=crop&q=60',
                'sizes' => '600ml',
                'ingredients' => 'Coca-Cola Sin Azúcar',
                'stock' => 60
            ],
            [
                'name' => 'Refresco Sprite Limón 600ml',
                'description' => 'Refresco sabor lima-limón burbujeante y refrescante. Botella de 600ml.',
                'price' => 28.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1625772290748-390939a2001e?w=600&auto=format&fit=crop&q=60',
                'sizes' => '600ml',
                'ingredients' => 'Sprite',
                'stock' => 50
            ],
            [
                'name' => 'Refresco Fanta Naranja 600ml',
                'description' => 'Refresco burbujeante con delicioso y divertido sabor a naranja. Botella de 600ml.',
                'price' => 28.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1624514288732-c65d648fa3df?w=600&auto=format&fit=crop&q=60',
                'sizes' => '600ml',
                'ingredients' => 'Fanta Naranja',
                'stock' => 50
            ],
            [
                'name' => 'Agua Embotellada Ciel 1L',
                'description' => 'Agua purificada Ciel de 1 litro para refrescarte de forma natural.',
                'price' => 24.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1523362628745-0c100150b504?w=600&auto=format&fit=crop&q=60',
                'sizes' => '1 Litro',
                'ingredients' => 'Agua Ciel',
                'stock' => 70
            ],
            [
                'name' => 'Té Helado de Limón Casero 500ml',
                'description' => 'Té negro infusionado con jugo de limón fresco y endulzado ligeramente. Servido muy frío.',
                'price' => 35.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&auto=format&fit=crop&q=60',
                'sizes' => '500ml',
                'ingredients' => 'Té Negro,Limón,Azúcar',
                'stock' => 40
            ],
            [
                'name' => 'Malteada de Fresa Cremosa',
                'description' => 'Malteada clásica batida con helado de fresa premium, leche entera, crema batida y una cereza arriba.',
                'price' => 49.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1579954115545-a95591f28bfc?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Helado Fresa,Leche,Crema Batida,Cereza',
                'stock' => 30
            ],
            [
                'name' => 'Malteada de Chocolate Belga',
                'description' => 'Deliciosa malteada elaborada con helado de chocolate belga, jarabe de chocolate, crema y chispas.',
                'price' => 49.00,
                'category' => 'drinks',
                'image_url' => 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Estándar',
                'ingredients' => 'Helado Chocolate,Jarabe Chocolate,Crema,Chispas',
                'stock' => 30
            ],

            // PROMOS (PAQUETES)
            [
                'name' => 'Paquete Combo Chaparrito',
                'description' => '1 Pizza Grande Chaparrita Especial + 1 Porción de Papas Gajo + 1 Refresco de 2 Litros.',
                'price' => 299.00,
                'category' => 'promos',
                'image_url' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Familiar',
                'ingredients' => 'Pizza Familiar,Papas Gajo,Refresco 2L',
                'stock' => 15
            ],
            [
                'name' => 'Combo Pareja Pizza',
                'description' => '1 Pizza Mediana a elegir del menú + 1 Orden de Dedos de Queso + 2 Refrescos de 600ml a elegir.',
                'price' => 269.00,
                'category' => 'promos',
                'image_url' => 'https://images.unsplash.com/photo-1590947132387-155cc02f3212?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Mediana',
                'ingredients' => 'Pizza Mediana,Dedos de Queso,2 Refrescos 600ml',
                'stock' => 20
            ],
            [
                'name' => 'Megacombo Familiar',
                'description' => '2 Pizzas Familiares a elegir del menú + 1 Orden de Alitas BBQ + 1 Refresco familiar de 2 Litros.',
                'price' => 499.00,
                'category' => 'promos',
                'image_url' => 'https://images.unsplash.com/photo-1565299585323-38d6b0865b47?w=600&auto=format&fit=crop&q=60',
                'sizes' => 'Familiar',
                'ingredients' => '2 Pizzas Familiares,Alitas BBQ,Refresco 2L',
                'stock' => 12
            ]
        ];

        foreach ($seedProducts as $sp) {
            $stmtCheck = $pdo->prepare("SELECT id FROM products WHERE name = ?");
            $stmtCheck->execute([$sp['name']]);
            $row = $stmtCheck->fetch();

            if ($row) {
                // Actualizar para asegurar datos correctos en el catálogo
                $stmtUpdate = $pdo->prepare("
                    UPDATE products 
                    SET description = ?, price = ?, category = ?, image_url = ?, sizes = ?, ingredients = ? 
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $sp['description'],
                    $sp['price'],
                    $sp['category'],
                    $sp['image_url'],
                    $sp['sizes'],
                    $sp['ingredients'],
                    $row['id']
                ]);

                // Verificar inventario
                $stmtInvCheck = $pdo->prepare("SELECT id FROM inventory WHERE product_id = ?");
                $stmtInvCheck->execute([$row['id']]);
                if (!$stmtInvCheck->fetch()) {
                    $stmtInvInsert = $pdo->prepare("INSERT INTO inventory (product_id, stock_quantity, min_stock) VALUES (?, ?, 5)");
                    $stmtInvInsert->execute([$row['id'], $sp['stock']]);
                }
            } else {
                // Insertar producto
                $stmtInsert = $pdo->prepare("
                    INSERT INTO products (name, description, price, category, image_url, sizes, ingredients) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsert->execute([
                    $sp['name'],
                    $sp['description'],
                    $sp['price'],
                    $sp['category'],
                    $sp['image_url'],
                    $sp['sizes'],
                    $sp['ingredients']
                ]);
                $newId = $pdo->lastInsertId();

                // Insertar inventario
                $stmtInvInsert = $pdo->prepare("INSERT INTO inventory (product_id, stock_quantity, min_stock) VALUES (?, ?, 5)");
                $stmtInvInsert->execute([$newId, $sp['stock']]);
            }
        }

        // Sembrar cupones si está vacía
        $stmtOffers = $pdo->query("SELECT COUNT(*) as count FROM offers");
        $offRow = $stmtOffers->fetch();
        if ($offRow && (int)$offRow['count'] === 0) {
            $coupons = [
                ['CHAPARRITO20', 'Descuento especial del 20%', 20],
                ['PIZZALOVE', 'Amor por la pizza del 10%', 10],
                ['BIENVENIDO', 'Cupón de bienvenida del 15%', 15],
                ['BENDITAPIPINA', 'Cupón especial 15%', 15]
            ];
            foreach ($coupons as $c) {
                $stmtC = $pdo->prepare("INSERT INTO offers (code, description, discount_percent, is_active) VALUES (?, ?, ?, TRUE)");
                $stmtC->execute($c);
            }
        }

        // Sembrar usuario administrador por defecto si no existe en base vacía
        $stmtAdmin = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtAdmin->execute(['admin@chaparritos.com']);
        if (!$stmtAdmin->fetch()) {
            $stmtInsertAdmin = $pdo->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
            $stmtInsertAdmin->execute([
                'Administrador Chaparritos',
                'admin@chaparritos.com',
                '5551234567',
                'admin',
                'admin'
            ]);
        }

        // Sembrar usuario cliente por defecto si no existe en base vacía
        $stmtClient = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtClient->execute(['cliente@chaparritos.com']);
        if (!$stmtClient->fetch()) {
            $stmtInsertClient = $pdo->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
            $stmtInsertClient->execute([
                'Josue Ponce (Cliente)',
                'cliente@chaparritos.com',
                '5559876543',
                'cliente',
                'client'
            ]);
        }
    } catch (\Exception $e) {
        error_log('Error en checkAndSeedDatabase: ' . $e->getMessage());
    }
}

// Método de la petición
$method = $_SERVER['REQUEST_METHOD'];

// Obtener y normalizar la ruta solicitada
$pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
if (empty($pathInfo)) {
    $reqUri = $_SERVER['REQUEST_URI'];
    $reqUri = explode('?', $reqUri)[0];
    $pos = strpos($reqUri, 'api.php');
    if ($pos !== false) {
        $pathInfo = substr($reqUri, $pos + 7);
    }
}

// Estandarizar ruta
$route = $pathInfo;
if (strpos($route, '/api') === 0) {
    $route = substr($route, 4); // Remover '/api'
}
$route = rtrim($route, '/');
if (empty($route)) {
    $route = '/';
}

// Función auxiliar para leer JSON enviado en el cuerpo de la petición (POST/PUT)
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

// ========================================================
// RUTEADOR PRINCIPAL API REST
// ========================================================

if ($route === '/' && $method === 'GET') {
    // 1. Estado de la API
    echo json_encode([
        'success' => true,
        'message' => '¡Servidor API PHP de Chaparritos Pizza funcionando!',
        'database_status' => 'CONECTADO (MySQL)',
        'version' => '1.0.0',
        'mexican_pesos_enabled' => true
    ]);
    exit;

} elseif ($route === '/auth/register' && $method === 'POST') {
    // 2. Registro de usuario
    $input = getJsonInput();
    $name = isset($input['name']) ? trim($input['name']) : '';
    $email = isset($input['email']) ? trim($input['email']) : '';
    $phone = isset($input['phone']) ? trim($input['phone']) : '';
    $password = isset($input['password']) ? $input['password'] : '';

    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios.']);
        exit;
    }

    if (strpos($email, '@') === false || strpos($email, '.') === false) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico no es válido.']);
        exit;
    }

    if (strlen($password) < 4) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 4 caracteres.']);
        exit;
    }

    if (strlen($phone) < 10) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El teléfono debe tener al menos 10 dígitos.']);
        exit;
    }

    // Verificar si el correo ya existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está registrado.']);
        exit;
    }

    // Determinar rol (admin si es admin@chaparritos.com)
    $role = strtolower($email) === 'admin@chaparritos.com' ? 'admin' : 'client';

    // Insertar usuario
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $password, $role]);
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Usuario registrado con éxito.',
        'user' => [
            'id' => (int)$newId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role' => $role
        ]
    ]);
    exit;

} elseif ($route === '/auth/login' && $method === 'POST') {
    // 3. Inicio de sesión
    $input = getJsonInput();
    $email = isset($input['email']) ? trim($input['email']) : '';
    $password = isset($input['password']) ? $input['password'] : '';

    if (empty($email) || empty($password)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El correo y la contraseña son requeridos.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico no está registrado.']);
        exit;
    }

    if ($userRow['password'] !== $password) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => '¡Bienvenido de nuevo, ' . $userRow['name'] . '!',
        'user' => [
            'id' => (int)$userRow['id'],
            'name' => $userRow['name'],
            'email' => $userRow['email'],
            'phone' => $userRow['phone'],
            'role' => $userRow['role']
        ]
    ]);
    exit;

} elseif ($route === '/auth/recover' && $method === 'POST') {
    // 4. Recuperar contraseña
    $input = getJsonInput();
    $email = isset($input['email']) ? trim($input['email']) : '';

    if (empty($email)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico es requerido.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT password, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico no está registrado.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Instrucciones de recuperación enviadas a ' . $email . '.',
        'demoPassword' => $userRow['password'],
        'userName' => $userRow['name']
    ]);
    exit;

} elseif ($route === '/products' && $method === 'GET') {
    // 5. Catálogo de productos público
    $stmt = $pdo->query("
        SELECT p.*, i.stock_quantity, i.min_stock 
        FROM products p 
        LEFT JOIN inventory i ON p.id = i.product_id 
        ORDER BY p.category, p.id
    ");
    $products = $stmt->fetchAll();

    // Formatear tipos de datos
    foreach ($products as &$p) {
        $p['id'] = (int)$p['id'];
        $p['price'] = (float)$p['price'];
        $p['is_available'] = (bool)$p['is_available'];
        $p['stock_quantity'] = $p['stock_quantity'] !== null ? (int)$p['stock_quantity'] : 50;
        $p['min_stock'] = $p['min_stock'] !== null ? (int)$p['min_stock'] : 5;
    }

    echo json_encode(['success' => true, 'products' => $products]);
    exit;

} elseif ($route === '/products/inventory' && $method === 'GET') {
    // 6. Inventario completo para Administrador
    $stmt = $pdo->query("
        SELECT p.id, p.name, p.category, p.price, p.is_available, i.stock_quantity, i.min_stock 
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id
        ORDER BY i.stock_quantity ASC, p.name ASC
    ");
    $inventory = $stmt->fetchAll();

    foreach ($inventory as &$item) {
        $item['id'] = (int)$item['id'];
        $item['price'] = (float)$item['price'];
        $item['is_available'] = (bool)$item['is_available'];
        $item['stock_quantity'] = $item['stock_quantity'] !== null ? (int)$item['stock_quantity'] : 50;
        $item['min_stock'] = $item['min_stock'] !== null ? (int)$item['min_stock'] : 5;
    }

    echo json_encode(['success' => true, 'inventory' => $inventory]);
    exit;

} elseif ($route === '/products/upload' && $method === 'POST') {
    // 6.5 Cargar Imagen Física de Producto (Administrador)
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'No se recibió ninguna imagen o hubo un error al cargarla.']);
        exit;
    }

    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Formato no permitido. Solo se aceptan JPG, PNG, WEBP y GIF.']);
        exit;
    }

    // Crear carpeta uploads si no existe
    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    // Nombre de archivo único
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (empty($ext)) {
        $ext = 'jpg';
    }
    $filename = time() . '_' . uniqid() . '.' . $ext;
    $destination = $uploadsDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode([
            'success' => true,
            'message' => 'Imagen cargada físicamente con éxito.',
            'image_url' => 'uploads/' . $filename
        ]);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo en el servidor.']);
    }
    exit;

} elseif ($route === '/products' && $method === 'POST') {
    // 7. Crear nuevo producto (Administrador)
    $input = getJsonInput();
    $name = isset($input['name']) ? trim($input['name']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $price = isset($input['price']) ? (float)$input['price'] : 0.0;
    $category = isset($input['category']) ? trim($input['category']) : '';
    $image_url = isset($input['image_url']) ? trim($input['image_url']) : '';
    $sizes = isset($input['sizes']) ? trim($input['sizes']) : 'Chica,Mediana,Familiar';
    $ingredients = isset($input['ingredients']) ? trim($input['ingredients']) : '';
    $stock = isset($input['stock']) ? (int)$input['stock'] : 50;

    if (empty($name) || $price <= 0 || empty($category) || empty($image_url)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Nombre, precio, categoría e imagen son obligatorios.']);
        exit;
    }

    // Insertar producto
    $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category, image_url, sizes, ingredients) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $description, $price, $category, $image_url, $sizes, $ingredients]);
    $newProductId = $pdo->lastInsertId();

    // Inicializar inventario
    $stmt = $pdo->prepare("INSERT INTO inventory (product_id, stock_quantity, min_stock) VALUES (?, ?, 5)");
    $stmt->execute([$newProductId, $stock]);

    echo json_encode([
        'success' => true,
        'message' => 'Producto creado y agregado al inventario con éxito.',
        'productId' => (int)$newProductId
    ]);
    exit;

} elseif (preg_match('#^/products/(\d+)$#', $route, $matches) && $method === 'PUT') {
    // 8. Editar producto (Administrador)
    $productId = (int)$matches[1];
    $input = getJsonInput();
    $name = isset($input['name']) ? trim($input['name']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $price = isset($input['price']) ? (float)$input['price'] : 0.0;
    $category = isset($input['category']) ? trim($input['category']) : '';
    $image_url = isset($input['image_url']) ? trim($input['image_url']) : '';
    $sizes = isset($input['sizes']) ? trim($input['sizes']) : 'Chica,Mediana,Familiar';
    $ingredients = isset($input['ingredients']) ? trim($input['ingredients']) : '';
    $is_available = isset($input['is_available']) ? (bool)$input['is_available'] : true;

    if (empty($name) || $price <= 0 || empty($category) || empty($image_url)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Nombre, precio, categoría e imagen son obligatorios.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, category = ?, image_url = ?, sizes = ?, ingredients = ?, is_available = ? WHERE id = ?");
    $stmt->execute([$name, $description, $price, $category, $image_url, $sizes, $ingredients, $is_available ? 1 : 0, $productId]);

    echo json_encode(['success' => true, 'message' => 'Producto actualizado con éxito.']);
    exit;

} elseif (preg_match('#^/products/(\d+)$#', $route, $matches) && $method === 'DELETE') {
    // 9. Eliminar producto (Administrador)
    $productId = (int)$matches[1];

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$productId]);

    echo json_encode(['success' => true, 'message' => 'Producto eliminado con éxito de catálogo e inventario.']);
    exit;

} elseif (preg_match('#^/products/(\d+)/stock$#', $route, $matches) && $method === 'PUT') {
    // 10. Actualizar stock del inventario (Administrador)
    $productId = (int)$matches[1];
    $input = getJsonInput();
    $stock_quantity = isset($input['stock_quantity']) ? (int)$input['stock_quantity'] : -1;

    if ($stock_quantity < 0) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Debe especificar una cantidad de stock válida.']);
        exit;
    }

    // Actualizar stock
    $stmt = $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE product_id = ?");
    $stmt->execute([$stock_quantity, $productId]);

    // Actualizar disponibilidad del producto automáticamente
    $isAvailable = $stock_quantity > 0 ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE products SET is_available = ? WHERE id = ?");
    $stmt->execute([$isAvailable, $productId]);

    echo json_encode(['success' => true, 'message' => 'Existencias de inventario actualizadas con éxito.']);
    exit;

} elseif (preg_match('#^/orders/coupon/([^/]+)$#', $route, $matches) && $method === 'GET') {
    // 11. Obtener detalles de un cupón
    $code = strtoupper($matches[1]);

    $stmt = $pdo->prepare("SELECT * FROM offers WHERE code = ? AND is_active = TRUE");
    $stmt->execute([$code]);
    $offer = $stmt->fetch();

    if (!$offer) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['success' => false, 'message' => 'Cupón inválido o expirado.']);
        exit;
    }

    $offer['id'] = (int)$offer['id'];
    $offer['discount_percent'] = (int)$offer['discount_percent'];
    $offer['is_active'] = (bool)$offer['is_active'];

    echo json_encode(['success' => true, 'offer' => $offer]);
    exit;

} elseif ($route === '/orders' && $method === 'POST') {
    // 12. Crear nuevo pedido (Checkout)
    $input = getJsonInput();
    $userId = isset($input['userId']) ? $input['userId'] : null;
    $guestName = isset($input['guestName']) ? trim($input['guestName']) : null;
    $guestPhone = isset($input['guestPhone']) ? trim($input['guestPhone']) : null;
    $orderType = isset($input['orderType']) ? trim($input['orderType']) : '';
    $deliveryAddress = isset($input['deliveryAddress']) ? trim($input['deliveryAddress']) : null;
    $deliveryLat = isset($input['deliveryLat']) ? $input['deliveryLat'] : null;
    $deliveryLng = isset($input['deliveryLng']) ? $input['deliveryLng'] : null;
    $deliveryDistance = isset($input['deliveryDistance']) ? (float)$input['deliveryDistance'] : 0.0;
    $estimatedTime = isset($input['estimatedTime']) ? trim($input['estimatedTime']) : '';
    $items = isset($input['items']) ? $input['items'] : [];
    $promoCode = isset($input['promoCode']) ? trim($input['promoCode']) : null;
    $paymentMethod = isset($input['paymentMethod']) ? trim($input['paymentMethod']) : '';
    $cardDetails = isset($input['cardDetails']) ? $input['cardDetails'] : null;

    if (empty($items)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'El carrito no puede estar vacío.']);
        exit;
    }

    if ($orderType === 'delivery' && $deliveryDistance > 10) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode([
            'success' => false,
            'message' => 'Lo sentimos, la ubicación excede el límite máximo de entrega a domicilio (10 km).'
        ]);
        exit;
    }

    // --- INICIAR TRANSACCIÓN PDO ---
    try {
        $pdo->beginTransaction();

        // 1. Validar stock
        foreach ($items as $item) {
            $itemId = (int)$item['productId'];
            $qty = (int)$item['quantity'];

            $stmt = $pdo->prepare("SELECT stock_quantity FROM inventory WHERE product_id = ?");
            $stmt->execute([$itemId]);
            $stockRow = $stmt->fetch();

            if ($stockRow) {
                $stock = (int)$stockRow['stock_quantity'];
                if ($stock < $qty) {
                    $stmtProd = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                    $stmtProd->execute([$itemId]);
                    $prodRow = $stmtProd->fetch();
                    $prodName = $prodRow ? $prodRow['name'] : 'Producto';
                    
                    $pdo->rollBack();
                    header('HTTP/1.1 400 Bad Request');
                    echo json_encode([
                        'success' => false,
                        'message' => "Inventario insuficiente para: \"{$prodName}\". Disponibles: {$stock}."
                    ]);
                    exit;
                }
            }
        }

        // 2. Calcular importes
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float)$item['unitPrice'] * (int)$item['quantity'];
        }

        $discountPercent = 0;
        $discountAmount = 0.0;

        if (!empty($promoCode)) {
            $stmtOffer = $pdo->prepare("SELECT discount_percent FROM offers WHERE code = ? AND is_active = TRUE");
            $stmtOffer->execute([strtoupper($promoCode)]);
            $offerRow = $stmtOffer->fetch();
            if ($offerRow) {
                $discountPercent = (int)$offerRow['discount_percent'];
                $discountAmount = $subtotal * ($discountPercent / 100);
            }
        }

        $totalAmount = $subtotal - $discountAmount;

        // Convertir userId "null" o vacío a null real
        $realUserId = ($userId === 'null' || empty($userId)) ? null : (int)$userId;

        // 3. Registrar el Pedido
        $stmtOrder = $pdo->prepare("
            INSERT INTO orders 
            (user_id, guest_name, guest_phone, order_type, delivery_address, delivery_lat, delivery_lng, delivery_distance, estimated_time, status, subtotal, discount_amount, total_amount, promo_code, payment_method) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'preparando', ?, ?, ?, ?, ?)
        ");
        $stmtOrder->execute([
            $realUserId,
            $guestName,
            $guestPhone,
            $orderType,
            $deliveryAddress,
            $deliveryLat,
            $deliveryLng,
            $deliveryDistance,
            $estimatedTime,
            $subtotal,
            $discountAmount,
            $totalAmount,
            $promoCode,
            $paymentMethod
        ]);
        $newOrderId = (int)$pdo->lastInsertId();

        // 4. Guardar los Items y actualizar el inventario
        foreach ($items as $item) {
            $itemId = (int)$item['productId'];
            $qty = (int)$item['quantity'];
            $unitPrice = (float)$item['unitPrice'];
            $size = trim($item['size']);
            $extraIngredients = isset($item['extraIngredients']) ? trim($item['extraIngredients']) : '';
            $itemTotalPrice = $unitPrice * $qty;

            // Obtener nombre del producto
            $stmtPName = $pdo->prepare("SELECT name FROM products WHERE id = ?");
            $stmtPName->execute([$itemId]);
            $pRow = $stmtPName->fetch();
            $productName = $pRow ? $pRow['name'] : 'Pizza / Complemento';

            // Insertar item
            $stmtItem = $pdo->prepare("
                INSERT INTO order_items 
                (order_id, product_id, product_name, size, quantity, unit_price, extra_ingredients, total_price) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtItem->execute([
                $newOrderId,
                $itemId,
                $productName,
                $size,
                $qty,
                $unitPrice,
                $extraIngredients,
                $itemTotalPrice
            ]);

            // Reducir stock
            $stmtRedStock = $pdo->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE product_id = ?");
            $stmtRedStock->execute([$qty, $itemId]);

            // Si el stock llega a 0, marcar el producto como no disponible
            $stmtCheckStock = $pdo->prepare("SELECT stock_quantity FROM inventory WHERE product_id = ?");
            $stmtCheckStock->execute([$itemId]);
            $newStockRow = $stmtCheckStock->fetch();
            if ($newStockRow && (int)$newStockRow['stock_quantity'] <= 0) {
                $stmtMarkNav = $pdo->prepare("UPDATE products SET is_available = FALSE WHERE id = ?");
                $stmtMarkNav->execute([$itemId]);
            }
        }

        // 5. Simular Pago con Tarjeta si aplica
        if ($paymentMethod === 'card') {
            $cardBrand = isset($cardDetails['cardBrand']) ? trim($cardDetails['cardBrand']) : 'Visa';
            $last4 = isset($cardDetails['last4']) ? trim($cardDetails['last4']) : '4242';

            $stmtPay = $pdo->prepare("INSERT INTO payments (order_id, card_brand, last4, transaction_status) VALUES (?, ?, ?, 'completed')");
            $stmtPay->execute([$newOrderId, $cardBrand, $last4]);
        }

        // Confirmar transacción
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Pedido procesado con éxito.',
            'orderId' => $newOrderId,
            'estimatedTime' => $estimatedTime,
            'total' => (float)$totalAmount
        ]);
        exit;

    } catch (\Exception $ex) {
        $pdo->rollBack();
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'message' => 'Error crítico al registrar el pedido: ' . $ex->getMessage()]);
        exit;
    }

} elseif (preg_match('#^/orders/user/([^/]+)$#', $route, $matches) && $method === 'GET') {
    // 13. Obtener historial de compras del usuario
    $userIdStr = $matches[1];
    $userId = ($userIdStr === 'null' || empty($userIdStr)) ? null : (int)$userIdStr;

    if ($userId === null) {
        // Invitados: traemos pedidos sin ID asignado
        $stmt = $pdo->query("SELECT * FROM orders WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 5");
        $orders = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll();
    }

    // Formatear tipos numéricos
    foreach ($orders as &$o) {
        $o['id'] = (int)$o['id'];
        $o['user_id'] = $o['user_id'] !== null ? (int)$o['user_id'] : null;
        $o['delivery_lat'] = $o['delivery_lat'] !== null ? (float)$o['delivery_lat'] : null;
        $o['delivery_lng'] = $o['delivery_lng'] !== null ? (float)$o['delivery_lng'] : null;
        $o['delivery_distance'] = $o['delivery_distance'] !== null ? (float)$o['delivery_distance'] : null;
        $o['subtotal'] = (float)$o['subtotal'];
        $o['discount_amount'] = (float)$o['discount_amount'];
        $o['total_amount'] = (float)$o['total_amount'];
    }

    echo json_encode(['success' => true, 'orders' => $orders]);
    exit;

} elseif ($route === '/orders/active' && $method === 'GET') {
    // 14. Obtener pedidos activos para el Administrador
    $stmt = $pdo->query("
        SELECT o.*, u.name as user_name, u.phone as user_phone 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.status != 'entregado' 
        ORDER BY o.created_at DESC
    ");
    $orders = $stmt->fetchAll();

    $ordersWithItems = [];
    foreach ($orders as $order) {
        $orderId = (int)$order['id'];
        
        $stmtItems = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll();

        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['order_id'] = (int)$item['order_id'];
            $item['product_id'] = $item['product_id'] !== null ? (int)$item['product_id'] : null;
            $item['quantity'] = (int)$item['quantity'];
            $item['unit_price'] = (float)$item['unit_price'];
            $item['total_price'] = (float)$item['total_price'];
        }

        $order['id'] = $orderId;
        $order['user_id'] = $order['user_id'] !== null ? (int)$order['user_id'] : null;
        $order['delivery_lat'] = $order['delivery_lat'] !== null ? (float)$order['delivery_lat'] : null;
        $order['delivery_lng'] = $order['delivery_lng'] !== null ? (float)$order['delivery_lng'] : null;
        $order['delivery_distance'] = $order['delivery_distance'] !== null ? (float)$order['delivery_distance'] : null;
        $order['subtotal'] = (float)$order['subtotal'];
        $order['discount_amount'] = (float)$order['discount_amount'];
        $order['total_amount'] = (float)$order['total_amount'];
        $order['items'] = $items;

        $ordersWithItems[] = $order;
    }

    echo json_encode(['success' => true, 'orders' => $ordersWithItems]);
    exit;

} elseif (preg_match('#^/orders/(\d+)/status$#', $route, $matches) && $method === 'PUT') {
    // 15. Actualizar estado del pedido (Administrador)
    $orderId = (int)$matches[1];
    $input = getJsonInput();
    $status = isset($input['status']) ? trim($input['status']) : '';

    $validStatuses = ['preparando', 'horno', 'listo', 'entregado'];
    if (!in_array($status, $validStatuses)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Estado del pedido inválido.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $orderId]);

    echo json_encode(['success' => true, 'message' => "Estado del pedido #{$orderId} cambiado a: {$status}"]);
    exit;

} elseif ($route === '/orders/stats' && $method === 'GET') {
    // 16. Estadísticas de Ventas (Administrador)
    
    // Ventas e ingresos totales
    $stmtRevenue = $pdo->query("SELECT SUM(total_amount) as total FROM orders");
    $revRow = $stmtRevenue->fetch();
    $totalRevenue = $revRow ? (float)$revRow['total'] : 0.0;

    $stmtOrders = $pdo->query("SELECT COUNT(*) as count FROM orders");
    $ordRow = $stmtOrders->fetch();
    $totalOrders = $ordRow ? (int)$ordRow['count'] : 0;

    // Productos con stock bajo
    $stmtLowStock = $pdo->query("SELECT COUNT(*) as count FROM inventory WHERE stock_quantity < min_stock");
    $lowStockRow = $stmtLowStock->fetch();
    $lowStockCount = $lowStockRow ? (int)$lowStockRow['count'] : 0;

    // Clientes registrados
    $stmtClients = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'client'");
    $clientRow = $stmtClients->fetch();
    $totalClients = $clientRow ? (int)$clientRow['count'] : 0;

    // Ventas por día (últimos 7 días)
    $stmtDaily = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%d-%b') as date, SUM(total_amount) as amount 
        FROM orders 
        GROUP BY DATE(created_at) 
        ORDER BY created_at DESC 
        LIMIT 7
    ");
    $dailySales = $stmtDaily->fetchAll();
    foreach ($dailySales as &$d) {
        $d['amount'] = (float)$d['amount'];
    }
    // Reversar para orden cronológico
    $dailySales = array_reverse($dailySales);

    // Top 5 productos vendidos
    $stmtTop = $pdo->query("
        SELECT product_name, SUM(quantity) as quantity 
        FROM order_items 
        GROUP BY product_name 
        ORDER BY quantity DESC 
        LIMIT 5
    ");
    $topProducts = $stmtTop->fetchAll();
    foreach ($topProducts as &$tp) {
        $tp['quantity'] = (int)$tp['quantity'];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'totalRevenue' => number_format($totalRevenue, 2, '.', ''),
            'totalOrders' => $totalOrders,
            'lowStockCount' => $lowStockCount,
            'totalClients' => $totalClients,
            'dailySales' => $dailySales,
            'topProducts' => $topProducts
        ]
    ]);
    exit;

} else {
    // 17. Ruta no encontrada
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['success' => false, 'message' => 'Ruta de la API no encontrada.']);
    exit;
}
