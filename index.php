<?php
declare(strict_types=1);

/*
 * FABRIC TOUCH - single-file PHP clothing store
 * Requirements: PHP 8+, PDO MySQL extension, MySQL/MariaDB.
 *
 * Setup:
 * 1) Create a MySQL database (e.g. fabric_touch) with utf8mb4.
 * 2) Set environment variables DB_HOST, DB_NAME, DB_USER, DB_PASSWORD.
 *    Defaults below are suitable for local XAMPP (root / blank password).
 * 3) Set ADMIN_PASSWORD to a strong password before publishing.
 * 4) Save this file as index.php in your web root.
 *
 * Online payment is intentionally NOT simulated. The online option creates
 * an unpaid order; connect a real payment provider before accepting payments.
 */

session_start();

function envv(string $key, string $default = ''): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money($value): string {
    return number_format((float)$value, 2) . ' DA';
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

$dbHost = envv('DB_HOST', '127.0.0.1');
$dbName = envv('DB_NAME', 'fabric_touch');
$dbUser = envv('DB_USER', 'root');
$dbPass = envv('DB_PASSWORD', '');

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1><p>Check DB_HOST, DB_NAME, DB_USER and DB_PASSWORD, and make sure the MySQL database exists.</p>';
    exit;
}

/* Create the store tables automatically. */
$pdo->exec("
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_ar VARCHAR(255) NOT NULL,
    name_fr VARCHAR(255) NOT NULL,
    description_ar TEXT NULL,
    description_fr TEXT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock INT NOT NULL DEFAULT 0,
    fabric_type VARCHAR(100) NOT NULL DEFAULT 'Cotton',
    image_url VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    address TEXT NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cod','online') NOT NULL,
    payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    order_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_ft_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* Insert demo products only when the catalogue is empty. */
$count = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
if ($count === 0) {
    $seed = $pdo->prepare("INSERT INTO products
        (name_ar,name_fr,description_ar,description_fr,price,stock,fabric_type,image_url)
        VALUES (?,?,?,?,?,?,?,?)");
    $demo = [
        ['قميص قطني','Chemise en coton','قميص قطني ناعم ومريح للاستخدام اليومي.','Chemise en coton doux et confortable pour tous les jours.',3500,20,'Cotton','https://images.unsplash.com/photo-1596755094514-f87e34085b2c?auto=format&fit=crop&w=900&q=85'],
        ['سترة جينز','Veste en jean','سترة جينز بتصميم عصري.','Veste en jean au design moderne.',6500,15,'Denim','https://images.unsplash.com/photo-1543076447-215ad9ba6923?auto=format&fit=crop&w=900&q=85'],
        ['كنزة صوفية','Pull en laine','كنزة دافئة وناعمة للأيام الباردة.','Pull doux et chaud pour les journées fraîches.',5000,12,'Wool','https://images.unsplash.com/photo-1576566588028-4147f3842f27?auto=format&fit=crop&w=900&q=85'],
    ];
    foreach ($demo as $row) {
        $seed->execute($row);
    }
}

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
if (!isset($_SESSION['lang'])) $_SESSION['lang'] = 'ar';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar','fr'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
    $target = strtok($_SERVER['REQUEST_URI'], '?');
    redirect($target ?: './');
}
$lang = $_SESSION['lang'];
$ar = $lang === 'ar';
$dir = $ar ? 'rtl' : 'ltr';
$t = [
    'brand' => 'FABRIC TOUCH',
    'home' => $ar ? 'الرئيسية' : 'Accueil',
    'catalogue' => $ar ? 'الملابس' : 'Collection',
    'cart' => $ar ? 'السلة' : 'Panier',
    'hero_kicker' => 'NEW COLLECTION 2026',
    'hero_title' => $ar ? 'أناقة تلامس تفاصيلها' : 'Le style dans chaque détail',
    'hero_text' => $ar ? 'اكتشف ملابس عصرية، وتعرّف على نسيج القماش بتجربة تفاعلية ثلاثية الأبعاد.' : 'Découvrez une mode moderne et explorez les tissus avec une expérience 3D interactive.',
    'shop' => $ar ? 'اكتشف المجموعة' : 'Découvrir la collection',
    'featured' => $ar ? 'اختياراتنا لك' : 'Notre sélection',
    'details' => $ar ? 'تفاصيل المنتج' : 'Voir le produit',
    'price' => $ar ? 'السعر' : 'Prix',
    'fabric' => $ar ? 'نوع القماش' : 'Tissu',
    'stock' => $ar ? 'المتوفر' : 'En stock',
    'add' => $ar ? 'أضف إلى السلة' : 'Ajouter au panier',
    'touch' => $ar ? 'استكشف القماش ثلاثي الأبعاد' : 'Explorer le tissu en 3D',
    'drag' => $ar ? 'اسحب داخل المشهد لتدوير القماش. حرّك المؤشر فوقه لمشاهدة تأثير التموج.' : 'Faites glisser pour tourner le tissu. Déplacez le pointeur pour voir les ondulations.',
    'empty' => $ar ? 'السلة فارغة حالياً.' : 'Votre panier est vide.',
    'quantity' => $ar ? 'الكمية' : 'Quantité',
    'remove' => $ar ? 'حذف' : 'Supprimer',
    'total' => $ar ? 'المجموع' : 'Total',
    'checkout' => $ar ? 'إتمام الطلب' : 'Commander',
    'name' => $ar ? 'الاسم الكامل' : 'Nom complet',
    'phone' => $ar ? 'رقم الهاتف' : 'Téléphone',
    'address' => $ar ? 'عنوان التوصيل' : 'Adresse de livraison',
    'payment' => $ar ? 'طريقة الدفع' : 'Mode de paiement',
    'cod' => $ar ? 'الدفع عند الاستلام' : 'Paiement à la livraison',
    'online' => $ar ? 'الدفع الإلكتروني (يتطلب ربط بوابة دفع)' : 'Paiement en ligne (passerelle à connecter)',
    'place_order' => $ar ? 'تأكيد الطلب' : 'Confirmer la commande',
    'success' => $ar ? 'تم تسجيل طلبك بنجاح. رقم الطلب:' : 'Commande enregistrée. Numéro :',
    'admin' => $ar ? 'الإدارة' : 'Administration',
    'admin_login' => $ar ? 'دخول الإدارة' : 'Connexion admin',
    'password' => $ar ? 'كلمة مرور الإدارة' : 'Mot de passe admin',
    'login' => $ar ? 'دخول' : 'Se connecter',
    'logout' => $ar ? 'خروج' : 'Déconnexion',
    'add_product' => $ar ? 'إضافة منتج' : 'Ajouter un produit',
    'product_name_ar' => 'اسم المنتج بالعربية',
    'product_name_fr' => 'Nom du produit en français',
    'description_ar' => 'الوصف بالعربية',
    'description_fr' => 'Description en français',
    'image' => $ar ? 'رابط صورة المنتج' : 'URL de l’image',
    'save' => $ar ? 'حفظ' : 'Enregistrer',
    'orders' => $ar ? 'الطلبات الأخيرة' : 'Commandes récentes',
    'status' => $ar ? 'الحالة' : 'Statut',
    'search' => $ar ? 'ابحث عن قطعة...' : 'Rechercher un article...',
    'all' => $ar ? 'الكل' : 'Tout',
];

$adminPassword = envv('ADMIN_PASSWORD', '');
$isAdmin = !empty($_SESSION['is_admin']);
$action = $_POST['action'] ?? '';

/* All state-changing forms require a CSRF token. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Invalid request token. Please refresh the page.');
    }

    if ($action === 'admin_login') {
        if ($adminPassword !== '' && hash_equals($adminPassword, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            redirect('?page=admin');
        }
        $flash = $ar ? 'كلمة المرور غير صحيحة أو ADMIN_PASSWORD غير مضبوط.' : 'Mot de passe incorrect ou ADMIN_PASSWORD non configuré.';
    }

    if ($action === 'admin_logout') {
        unset($_SESSION['is_admin']);
        session_regenerate_id(true);
        redirect('./');
    }

    if ($action === 'add_to_cart') {
        $id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $qty = max(1, min(20, (int)($_POST['quantity'] ?? 1)));
        $stmt = $pdo->prepare("SELECT id,stock FROM products WHERE id=?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if ($p && (int)$p['stock'] > 0) {
            $_SESSION['cart'][$id] = min((int)$p['stock'], (int)($_SESSION['cart'][$id] ?? 0) + $qty);
            $_SESSION['flash'] = $ar ? 'تمت إضافة المنتج إلى السلة.' : 'Article ajouté au panier.';
        }
        redirect('?page=cart');
    }

    if ($action === 'update_cart') {
        foreach (($_POST['qty'] ?? []) as $id => $qty) {
            $id = (int)$id;
            $qty = max(0, min(50, (int)$qty));
            if ($qty === 0) unset($_SESSION['cart'][$id]);
            else {
                $stmt = $pdo->prepare("SELECT stock FROM products WHERE id=?");
                $stmt->execute([$id]);
                $stock = $stmt->fetchColumn();
                if ($stock !== false) $_SESSION['cart'][$id] = min($qty, (int)$stock);
            }
        }
        redirect('?page=cart');
    }

    if ($action === 'checkout') {
        $customer = trim((string)($_POST['customer_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $method = (string)($_POST['payment_method'] ?? 'cod');

        if ($customer === '' || $phone === '' || $address === '' || !in_array($method, ['cod','online'], true) || empty($_SESSION['cart'])) {
            $flash = $ar ? 'يرجى ملء جميع الحقول والتحقق من السلة.' : 'Veuillez remplir tous les champs et vérifier le panier.';
        } else {
            try {
                $pdo->beginTransaction();
                $items = [];
                $total = 0.0;
                foreach ($_SESSION['cart'] as $id => $qty) {
                    $stmt = $pdo->prepare("SELECT id,name_ar,name_fr,price,stock FROM products WHERE id=? FOR UPDATE");
                    $stmt->execute([(int)$id]);
                    $p = $stmt->fetch();
                    if (!$p || (int)$p['stock'] < (int)$qty) {
                        throw new RuntimeException('Stock unavailable');
                    }
                    $items[] = [$p, (int)$qty];
                    $total += (float)$p['price'] * (int)$qty;
                }

                $stmt = $pdo->prepare("INSERT INTO orders (customer_name,phone,address,total,payment_method,payment_status,order_status) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$customer,$phone,$address,$total,$method,'pending','pending']);
                $orderId = (int)$pdo->lastInsertId();

                $insertItem = $pdo->prepare("INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)");
                $updateStock = $pdo->prepare("UPDATE products SET stock=stock-? WHERE id=?");
                foreach ($items as [$p,$qty]) {
                    $insertItem->execute([$orderId,$p['id'],$ar ? $p['name_ar'] : $p['name_fr'],$qty,$p['price']]);
                    $updateStock->execute([$qty,$p['id']]);
                }
                $pdo->commit();
                $_SESSION['cart'] = [];
                $_SESSION['last_order'] = $orderId;
                redirect('?page=success');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage());
                $flash = $ar ? 'تعذر تسجيل الطلب. تحقق من المخزون وحاول مجدداً.' : 'Impossible d’enregistrer la commande. Vérifiez le stock.';
            }
        }
    }

    if ($action === 'admin_add_product' && $isAdmin) {
        $nameAr = trim((string)($_POST['name_ar'] ?? ''));
        $nameFr = trim((string)($_POST['name_fr'] ?? ''));
        $descAr = trim((string)($_POST['description_ar'] ?? ''));
        $descFr = trim((string)($_POST['description_fr'] ?? ''));
        $price = (float)($_POST['price'] ?? 0);
        $stock = max(0, (int)($_POST['stock'] ?? 0));
        $fabric = trim((string)($_POST['fabric_type'] ?? 'Cotton'));
        $image = trim((string)($_POST['image_url'] ?? ''));
        if ($nameAr !== '' && $nameFr !== '' && $price > 0) {
            $stmt = $pdo->prepare("INSERT INTO products (name_ar,name_fr,description_ar,description_fr,price,stock,fabric_type,image_url) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$nameAr,$nameFr,$descAr,$descFr,$price,$stock,$fabric,$image]);
            redirect('?page=admin');
        }
        $flash = $ar ? 'أدخل اسم المنتج والسعر بشكل صحيح.' : 'Saisissez un nom et un prix valides.';
    }
}

$page = (string)($_GET['page'] ?? 'home');
$flash = $flash ?? ($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

function productName(array $p, bool $ar): string {
    return $ar ? (string)$p['name_ar'] : (string)$p['name_fr'];
}
function productDescription(array $p, bool $ar): string {
    return $ar ? (string)$p['description_ar'] : (string)$p['description_fr'];
}
function productImage(array $p): string {
    $url = trim((string)($p['image_url'] ?? ''));
    return $url !== '' ? $url : 'https://placehold.co/800x900/ece8df/333?text=FABRIC+TOUCH';
}

$cartCount = array_sum($_SESSION['cart']);
$products = [];
if ($page === 'home' || $page === 'catalogue') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE name_ar LIKE ? OR name_fr LIKE ? OR fabric_type LIKE ? ORDER BY id DESC");
        $like = '%' . $q . '%';
        $stmt->execute([$like,$like,$like]);
        $products = $stmt->fetchAll();
    } else {
        $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
    }
}
$product = null;
if ($page === 'product') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$id ?: 0]);
    $product = $stmt->fetch() ?: null;
}
$cartRows = [];
$cartTotal = 0.0;
if ($page === 'cart' || $page === 'checkout') {
    if (!empty($_SESSION['cart'])) {
        $ids = array_map('intval', array_keys($_SESSION['cart']));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($marks)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $p) {
            $qty = (int)$_SESSION['cart'][$p['id']];
            $line = (float)$p['price'] * $qty;
            $cartRows[] = ['product'=>$p,'qty'=>$qty,'line'=>$line];
            $cartTotal += $line;
        }
    }
}
$orders = [];
if ($page === 'admin' && $isAdmin) {
    $orders = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 30")->fetchAll();
}
?>
<!doctype html>
<html lang="<?= h($lang) ?>" dir="<?= h($dir) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($t['brand']) ?> — <?= h($t['catalogue']) ?></title>
<style>
:root{--ink:#20211f;--muted:#77786f;--paper:#f7f7f4;--sand:#e9e4da;--accent:#aa8d65;--white:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Arial,Tahoma,sans-serif}
a{color:inherit;text-decoration:none}button,input,textarea,select{font:inherit}
.topbar{height:auto;min-height:76px;background:#fff;display:flex;align-items:center;justify-content:space-between;padding:16px 6%;gap:20px;position:sticky;top:0;z-index:10;box-shadow:0 2px 15px #00000008}
.logo{font-size:22px;font-weight:800;letter-spacing:1px}.logo span{color:var(--accent)}
.nav{display:flex;gap:24px;align-items:center;flex-wrap:wrap}.nav a{font-size:14px;color:#444}.lang a{font-size:13px;padding:6px}
.hero{min-height:390px;padding:70px 8%;display:flex;align-items:center;background:linear-gradient(110deg,#eeeae2,#ded6c8)}
.hero-inner{max-width:650px}.kicker{letter-spacing:4px;color:#927650;font-size:12px;font-weight:bold}
.hero h1{font-size:clamp(34px,5vw,60px);line-height:1.2;margin:18px 0}.hero p{color:#5d5b55;line-height:1.9;max-width:550px}
.btn{display:inline-block;border:0;background:var(--ink);color:white;padding:13px 22px;border-radius:4px;cursor:pointer;margin-top:12px}
.btn.gold{background:var(--accent)}.btn.light{background:#eee;color:#222}
.section{padding:55px 6%}.section-head{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:28px}
.section h2{font-size:28px;margin:0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:22px}
.card{background:#fff;border-radius:10px;overflow:hidden;transition:.25s}.card:hover{transform:translateY(-5px);box-shadow:0 12px 35px #00000010}
.card img{width:100%;height:285px;object-fit:cover;background:#eee}.card-body{padding:18px}.card h3{margin:0 0 10px}.price{font-weight:bold;color:#9a7a4d;margin:12px 0}
.muted{color:var(--muted);font-size:14px;line-height:1.7}.search{display:flex;gap:8px}.search input,.field{padding:12px;border:1px solid #ddd;border-radius:5px;background:white;width:100%}
.product-layout{display:grid;grid-template-columns:1fr 1fr;gap:38px}.product-photo{width:100%;max-height:650px;object-fit:cover;border-radius:12px}.panel{background:#fff;padding:25px;border-radius:10px}
.viewer{margin-top:24px;background:#e9e5dc;border-radius:12px;overflow:hidden;padding:12px}.viewer-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.viewer canvas{width:100%;height:360px;display:block;touch-action:none}
.table-wrap{overflow:auto;background:#fff;border-radius:10px}table{width:100%;border-collapse:collapse;min-width:550px}th,td{padding:14px;border-bottom:1px solid #eee;text-align:start}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.form-group{margin-bottom:14px}.form-group label{display:block;margin-bottom:7px;font-size:14px}.notice{padding:13px 16px;background:#eee7da;border-radius:7px;margin:20px 6%}.footer{background:#20211f;color:#fff;text-align:center;padding:28px;margin-top:40px}
.empty{padding:40px;text-align:center;background:#fff;border-radius:10px}
@media(max-width:760px){.topbar{align-items:flex-start;flex-wrap:wrap}.nav{gap:14px}.hero{min-height:330px;padding:50px 7%}.product-layout{grid-template-columns:1fr}.section{padding:38px 5%}.form-grid{grid-template-columns:1fr}.viewer canvas{height:300px}}
</style>
</head>
<body>
<header class="topbar">
  <a class="logo" href="?">FABRIC<span>TOUCH</span></a>
  <nav class="nav">
    <a href="?"><?= h($t['home']) ?></a>
    <a href="?page=catalogue"><?= h($t['catalogue']) ?></a>
    <a href="?page=cart"><?= h($t['cart']) ?> (<?= (int)$cartCount ?>)</a>
    <a href="?page=admin"><?= h($t['admin']) ?></a>
  </nav>
  <div class="lang"><a href="?lang=ar">AR</a> | <a href="?lang=fr">FR</a></div>
</header>

<?php if (!empty($flash)): ?><div class="notice"><?= h($flash) ?></div><?php endif; ?>

<?php if ($page === 'home' || $page === 'catalogue'): ?>
<section class="hero">
  <div class="hero-inner">
    <div class="kicker"><?= h($t['hero_kicker']) ?></div>
    <h1><?= h($t['hero_title']) ?></h1>
    <p><?= h($t['hero_text']) ?></p>
    <a class="btn" href="#products"><?= h($t['shop']) ?></a>
  </div>
</section>
<section class="section" id="products">
  <div class="section-head">
    <h2><?= h($t['featured']) ?></h2>
    <form class="search" method="get">
      <input type="hidden" name="page" value="catalogue">
      <input name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="<?= h($t['search']) ?>">
      <button class="btn" type="submit"><?= $ar ? 'بحث' : 'Chercher' ?></button>
    </form>
  </div>
  <div class="grid">
    <?php foreach ($products as $p): ?>
    <article class="card">
      <a href="?page=product&id=<?= (int)$p['id'] ?>">
        <img src="<?= h(productImage($p)) ?>" alt="<?= h(productName($p,$ar)) ?>" loading="lazy">
      </a>
      <div class="card-body">
        <h3><?= h(productName($p,$ar)) ?></h3>
        <p class="muted"><?= h($p['fabric_type']) ?></p>
        <div class="price"><?= money($p['price']) ?></div>
        <a class="btn" href="?page=product&id=<?= (int)$p['id'] ?>"><?= h($t['details']) ?></a>
      </div>
    </article>
    <?php endforeach; ?>
    <?php if (!$products): ?><div class="empty"><?= $ar ? 'لا توجد منتجات.' : 'Aucun produit.' ?></div><?php endif; ?>
  </div>
</section>

<?php elseif ($page === 'product'): ?>
<section class="section">
<?php if (!$product): ?>
  <div class="empty"><?= $ar ? 'المنتج غير موجود.' : 'Produit introuvable.' ?></div>
<?php else: ?>
  <div class="product-layout">
    <div>
      <img class="product-photo" src="<?= h(productImage($product)) ?>" alt="<?= h(productName($product,$ar)) ?>">
      <div class="viewer">
        <div class="viewer-head"><strong><?= h($t['touch']) ?></strong><span class="muted">3D / WebGL</span></div>
        <div id="fabric3d"></div>
        <p class="muted"><?= h($t['drag']) ?></p>
      </div>
    </div>
    <div class="panel">
      <h1><?= h(productName($product,$ar)) ?></h1>
      <p class="muted"><?= h(productDescription($product,$ar)) ?></p>
      <div class="price"><?= money($product['price']) ?></div>
      <p><?= h($t['fabric']) ?>: <strong><?= h($product['fabric_type']) ?></strong></p>
      <p><?= h($t['stock']) ?>: <?= (int)$product['stock'] ?></p>
      <?php if ((int)$product['stock'] > 0): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="add_to_cart">
        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
        <label><?= h($t['quantity']) ?></label>
        <input class="field" style="max-width:120px" type="number" name="quantity" min="1" max="<?= (int)$product['stock'] ?>" value="1">
        <button class="btn gold" type="submit"><?= h($t['add']) ?></button>
      </form>
      <?php else: ?><p class="muted"><?= $ar ? 'نفد المخزون.' : 'Rupture de stock.' ?></p><?php endif; ?>
    </div>
  </div>
  <script type="module">
  import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.170.0/build/three.module.js';
  const host = document.getElementById('fabric3d');
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0xe9e5dc);
  const camera = new THREE.PerspectiveCamera(43, 1, 0.1, 100);
  camera.position.set(0,0,5);
  const renderer = new THREE.WebGLRenderer({antialias:true,alpha:false});
  renderer.setPixelRatio(Math.min(devicePixelRatio,2));
  host.appendChild(renderer.domElement);
  scene.add(new THREE.AmbientLight(0xffffff,1.35));
  const key = new THREE.DirectionalLight(0xffffff,2.1); key.position.set(2,3,4); scene.add(key);
  const fill = new THREE.PointLight(0xd9c5a4,1.1); fill.position.set(-3,-1,2); scene.add(fill);
  const geo = new THREE.PlaneGeometry(3.6,2.45,75,55);
  const mat = new THREE.MeshStandardMaterial({color:0xb99b76,roughness:0.93,metalness:0,side:THREE.DoubleSide});
  const cloth = new THREE.Mesh(geo,mat); scene.add(cloth);
  const base = geo.attributes.position.array.slice();
  let down=false,px=0,py=0,mouseX=0,mouseY=0;
  const resize=()=>{const w=host.clientWidth||600,h=360;camera.aspect=w/h;camera.updateProjectionMatrix();renderer.setSize(w,h);};
  resize(); new ResizeObserver(resize).observe(host);
  renderer.domElement.style.width='100%'; renderer.domElement.style.height='360px'; renderer.domElement.style.touchAction='none';
  renderer.domElement.addEventListener('pointerdown',e=>{down=true;px=e.clientX;py=e.clientY;renderer.domElement.setPointerCapture(e.pointerId);});
  renderer.domElement.addEventListener('pointermove',e=>{const r=renderer.domElement.getBoundingClientRect();mouseX=((e.clientX-r.left)/r.width-.5)*2;mouseY=((e.clientY-r.top)/r.height-.5)*2;if(down){cloth.rotation.y+=(e.clientX-px)*.006;cloth.rotation.x+=(e.clientY-py)*.006;px=e.clientX;py=e.clientY;}});
  const stop=()=>down=false;renderer.domElement.addEventListener('pointerup',stop);renderer.domElement.addEventListener('pointercancel',stop);
  function animate(ms){requestAnimationFrame(animate);const t=ms*.001;for(let i=0;i<geo.attributes.position.count;i++){const x=base[i*3],y=base[i*3+1];const d=Math.exp(-((x-mouseX*1.5)**2+(y+mouseY*1.0)**2)*2.2);const wave=Math.sin(x*3.2+t*1.7)*.085+Math.sin(y*4.1-t)*.045;const pull=down?d*.12:0;geo.attributes.position.setZ(i,base[i*3+2]+wave+pull);}geo.attributes.position.needsUpdate=true;geo.computeVertexNormals();renderer.render(scene,camera);}
  animate(0);
  </script>
<?php endif; ?>
</section>

<?php elseif ($page === 'cart'): ?>
<section class="section">
<h2><?= h($t['cart']) ?></h2>
<?php if (!$cartRows): ?><div class="empty"><?= h($t['empty']) ?><p><a class="btn" href="?page=catalogue"><?= h($t['shop']) ?></a></p></div>
<?php else: ?>
<form method="post" class="table-wrap">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="update_cart">
<table><thead><tr><th><?= h($t['catalogue']) ?></th><th><?= h($t['price']) ?></th><th><?= h($t['quantity']) ?></th><th><?= h($t['total']) ?></th></tr></thead><tbody>
<?php foreach ($cartRows as $row): $p=$row['product']; ?>
<tr><td><?= h(productName($p,$ar)) ?></td><td><?= money($p['price']) ?></td><td><input class="field" style="max-width:90px" type="number" min="0" max="<?= (int)$p['stock'] ?>" name="qty[<?= (int)$p['id'] ?>]" value="<?= (int)$row['qty'] ?>"></td><td><?= money($row['line']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<div style="padding:20px;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap"><strong><?= h($t['total']) ?>: <?= money($cartTotal) ?></strong><button class="btn light" type="submit"><?= $ar ? 'تحديث السلة' : 'Mettre à jour' ?></button></div>
</form>
<a class="btn gold" href="?page=checkout"><?= h($t['checkout']) ?></a>
<?php endif; ?>
</section>

<?php elseif ($page === 'checkout'): ?>
<section class="section"><div class="panel" style="max-width:700px;margin:auto">
<h2><?= h($t['checkout']) ?></h2>
<?php if (!$cartRows): ?><p><?= h($t['empty']) ?> <a href="?page=catalogue"><?= h($t['shop']) ?></a></p>
<?php else: ?>
<p class="price"><?= h($t['total']) ?>: <?= money($cartTotal) ?></p>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="checkout">
<div class="form-group"><label><?= h($t['name']) ?></label><input class="field" name="customer_name" required maxlength="150"></div>
<div class="form-group"><label><?= h($t['phone']) ?></label><input class="field" name="phone" required maxlength="40" inputmode="tel"></div>
<div class="form-group"><label><?= h($t['address']) ?></label><textarea class="field" name="address" required rows="3"></textarea></div>
<div class="form-group"><label><?= h($t['payment']) ?></label><select class="field" name="payment_method"><option value="cod"><?= h($t['cod']) ?></option><option value="online"><?= h($t['online']) ?></option></select></div>
<p class="muted"><?= $ar ? 'الدفع الإلكتروني هنا يسجل الطلب كغير مدفوع فقط. يجب دمج بوابة دفع حقيقية قبل تفعيله.' : 'Le paiement en ligne est enregistré comme non payé. Intégrez une vraie passerelle avant activation.' ?></p>
<button class="btn gold" type="submit"><?= h($t['place_order']) ?></button>
</form>
<?php endif; ?></div></section>

<?php elseif ($page === 'success'): ?>
<section class="section"><div class="empty"><h2><?= $ar ? 'شكراً لطلبك!' : 'Merci pour votre commande !' ?></h2><p><?= h($t['success']) ?> <strong>#<?= (int)($_SESSION['last_order'] ?? 0) ?></strong></p><a class="btn" href="?"><?= h($t['home']) ?></a></div></section>

<?php elseif ($page === 'admin'): ?>
<section class="section">
<?php if (!$isAdmin): ?>
<div class="panel" style="max-width:450px;margin:auto"><h2><?= h($t['admin_login']) ?></h2>
<?php if ($adminPassword === ''): ?><p class="muted"><?= $ar ? 'اضبط ADMIN_PASSWORD في متغيرات البيئة أولاً.' : 'Définissez d’abord ADMIN_PASSWORD dans les variables d’environnement.' ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="admin_login"><input class="field" type="password" name="password" placeholder="<?= h($t['password']) ?>" required><button class="btn" type="submit"><?= h($t['login']) ?></button></form></div>
<?php else: ?>
<div class="section-head"><h2><?= h($t['admin']) ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="admin_logout"><button class="btn light"><?= h($t['logout']) ?></button></form></div>
<div class="panel">
<h3><?= h($t['add_product']) ?></h3>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="admin_add_product">
<div class="form-grid">
<div class="form-group"><label><?= h($t['product_name_ar']) ?></label><input class="field" name="name_ar" required></div>
<div class="form-group"><label><?= h($t['product_name_fr']) ?></label><input class="field" name="name_fr" required></div>
<div class="form-group"><label><?= h($t['description_ar']) ?></label><textarea class="field" name="description_ar"></textarea></div>
<div class="form-group"><label><?= h($t['description_fr']) ?></label><textarea class="field" name="description_fr"></textarea></div>
<div class="form-group"><label><?= h($t['price']) ?> (DA)</label><input class="field" name="price" type="number" min="1" step=".01" required></div>
<div class="form-group"><label><?= h($t['stock']) ?></label><input class="field" name="stock" type="number" min="0" value="0"></div>
<div class="form-group"><label><?= h($t['fabric']) ?></label><select class="field" name="fabric_type"><option>Cotton</option><option>Denim</option><option>Wool</option><option>Silk</option><option>Linen</option><option>Polyester</option></select></div>
<div class="form-group"><label><?= h($t['image']) ?></label><input class="field" name="image_url" type="url"></div>
</div><button class="btn gold" type="submit"><?= h($t['save']) ?></button></form>
</div>
<h3 style="margin-top:35px"><?= h($t['orders']) ?></h3>
<div class="table-wrap"><table><thead><tr><th>#</th><th><?= h($t['name']) ?></th><th><?= h($t['phone']) ?></th><th><?= h($t['total']) ?></th><th><?= h($t['payment']) ?></th><th><?= h($t['status']) ?></th></tr></thead><tbody>
<?php foreach ($orders as $o): ?><tr><td><?= (int)$o['id'] ?></td><td><?= h($o['customer_name']) ?></td><td><?= h($o['phone']) ?></td><td><?= money($o['total']) ?></td><td><?= h($o['payment_method']) ?></td><td><?= h($o['order_status']) ?></td></tr><?php endforeach; ?>
<?php if (!$orders): ?><tr><td colspan="6"><?= $ar ? 'لا توجد طلبات بعد.' : 'Aucune commande.' ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
</section>
<?php else: ?>
<section class="section"><div class="empty">404</div></section>
<?php endif; ?>

<footer class="footer">FABRIC TOUCH © <?= date('Y') ?> — <?= $ar ? 'الأناقة في كل تفصيل' : 'Le style dans chaque détail' ?></footer>
</body>
</html>
