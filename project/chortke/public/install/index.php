<?php
/**
 * Chortke Super Strong Graphical Installer v4.5 (Security Hardened)
 * - Enforces CSRF & Production Web Lock
 * - Enforces strong password policy for Super Admin (12+ chars, mixed case, numbers, symbols)
 * - Creates actual Super Admin user in database with Argon2id password hash
 * - Validates migration exit status; fails closed if migrations fail
 */

session_start();
define('INSTALLER_VERSION', '4.5.0-hardened');

// Generate CSRF token if not set
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Production safety guard
 */
function installer_env_value(string $key, ?string $default = null): ?string
{
    $runtime = getenv($key);
    if ($runtime !== false && $runtime !== '') {
        return (string)$runtime;
    }

    $envPath = __DIR__ . '/../../.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return $default;
    }

    foreach ((array)file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$envKey, $envValue] = array_map('trim', explode('=', $line, 2));
        if ($envKey === $key) {
            return trim($envValue, "\"'");
        }
    }

    return $default;
}

$installerAppEnv = strtolower((string)installer_env_value('APP_ENV', 'local'));
$allowWebInstaller = filter_var(installer_env_value('ALLOW_WEB_INSTALLER', 'false'), FILTER_VALIDATE_BOOLEAN);

if (file_exists(__DIR__ . '/../../storage/installed.lock')) {
    http_response_code(403);
    die('<h1 style="color:#dc2626;text-align:center;margin-top:80px">نصب قبلاً انجام شده است</h1><p style="text-align:center">پوشه install را حذف یا تغییر نام دهید.</p>');
}

if ($installerAppEnv === 'production' && !$allowWebInstaller) {
    http_response_code(403);
    die('<h1 style="color:#dc2626;text-align:center;margin-top:80px">نصب‌کننده وب در محیط production غیرفعال است</h1><p style="text-align:center">برای استفاده موقت، ALLOW_WEB_INSTALLER=true را فقط در پنجره نگهداری فعال کنید یا از دستور CLI استفاده نمایید.</p>');
}

$step = max(1, min(7, (int)($_GET['step'] ?? 1)));
$errors = [];

function check_req($name, $ok, $req = true) { return ['name'=>$name,'ok'=>$ok,'required'=>$req]; }
function gen_key($l=64){return bin2hex(random_bytes($l/2));}
function test_db($h,$p,$n,$u,$pw){try{$pdo=new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4",$u,$pw,[PDO::ATTR_TIMEOUT=>4]);return['ok'=>true,'pdo'=>$pdo];}catch(Throwable $e){return['ok'=>false,'msg'=>$e->getMessage()];}}
function test_redis($h,$p,$pw=''){if(!extension_loaded('redis'))return['ok'=>false];try{$r=new Redis();$r->connect($h,(int)$p,2);if($pw)$r->auth($pw);$r->ping();return['ok'=>true];}catch(Throwable $e){return['ok'=>false];}}
function write_env($d){$p=__DIR__.'/../../.env';$ex=file_exists(__DIR__.'/../../.env.example')?file_get_contents(__DIR__.'/../../.env.example'):'';foreach($d as $k=>$v){$v=str_replace(["\n","\r"],'',(string)$v);$ex=preg_match("/^$k=/m",$ex)?preg_replace("/^$k=.*/m","$k=$v",$ex):$ex."\n$k=$v";}return file_put_contents($p,$ex)!==false;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    // CSRF Guard for installer
    $csrfSubmitted = $_POST['_csrf_token'] ?? '';
    if (!hash_equals($_SESSION['_csrf_token'] ?? '', (string)$csrfSubmitted)) {
        $errors[] = "خطای امنیتی CSRF: توکن نامعتبر است.";
    } else {
        if($step==2){
            $db=['DB_HOST'=>trim($_POST['db_host']??'127.0.0.1'),'DB_PORT'=>trim($_POST['db_port']??'3306'),'DB_NAME'=>trim($_POST['db_name']??'chortke'),'DB_USER'=>trim($_POST['db_user']??'chortke_user'),'DB_PASS'=>trim($_POST['db_pass']??'')];
            $testRes = test_db($db['DB_HOST'],$db['DB_PORT'],$db['DB_NAME'],$db['DB_USER'],$db['DB_PASS']);
            if(!$testRes['ok']){$errors[]="اتصال دیتابیس ناموفق: " . ($testRes['msg'] ?? '');}else{$_SESSION['db']=$db;header("Location:?step=3");exit;}
        }
        if($step==3){
            $rd=['REDIS_HOST'=>trim($_POST['redis_host']??'127.0.0.1'),'REDIS_PORT'=>trim($_POST['redis_port']??'6379'),'REDIS_PASSWORD'=>trim($_POST['redis_pass']??'')];
            $qd=$_POST['queue_driver']??'redis';
            if($qd==='redis' && !test_redis($rd['REDIS_HOST'],$rd['REDIS_PORT'],$rd['REDIS_PASSWORD'])['ok']){$errors[]="اتصال Redis ناموفق";}else{$_SESSION['redis']=$rd;$_SESSION['queue']=$qd;header("Location:?step=4");exit;}
        }
        if($step==4){
            $_SESSION['app']=['APP_NAME'=>trim($_POST['app_name']??'چرتکه'),'APP_ENV'=>'production','APP_DEBUG'=>'false','APP_URL'=>rtrim(trim($_POST['app_url']??'http://127.0.0.1:8080'),'/'),'APP_KEY'=>gen_key(64),'SECURITY_API_TOKEN_SECRET'=>gen_key(64),'ZARINPAL_MERCHANT_ID'=>trim($_POST['zarinpal']??''),'KAVENEGAR_API_KEY'=>trim($_POST['kavenegar']??'')];
            header("Location:?step=5");exit;
        }
        if($step==5){
            $em=trim($_POST['admin_email']??'admin@chortke.ir');
            $pw=trim($_POST['admin_pass']??'');
            
            // Strong Password Policy: 12+ chars, uppercase, lowercase, numbers, symbols
            if(strlen($pw)<12){
                $errors[]="رمز عبور مدیر ارشد باید حداقل ۱۲ کاراکتر باشد.";
            } elseif(!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw) || !preg_match('/[^a-zA-Z0-9]/', $pw)) {
                $errors[]="رمز عبور مدیر ارشد باید شامل حروف کوچک، حروف بزرگ، اعداد و کاراکترهای ویژه (مانند !@#$) باشد.";
            } else {
                $_SESSION['admin']=['email'=>$em,'pass'=>$pw];
                header("Location:?step=6");
                exit;
            }
        }
        if($step==6){
            $wm=$_POST['worker_method']??'supervisor';
            $adminCreds = $_SESSION['admin'] ?? [];
            $env=array_merge($_SESSION['db']??[],$_SESSION['redis']??[],$_SESSION['app']??[]);
            $env['QUEUE_CONNECTION']=$_SESSION['queue']??'redis';
            if (!empty($adminCreds['email'])) {
                $env['ADMIN_EMAIL'] = $adminCreds['email'];
                $env['ADMIN_PASSWORD'] = $adminCreds['pass'];
            }

            if(!write_env($env)){
                $errors[]="نوشتن فایل .env ناموفق بود.";
            } else {
                foreach($env as $k=>$v) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
                
                // Execute Migration Runner via MigrationService
                require_once __DIR__ . '/../../bootstrap/app.php';
                $container = \Core\Container::getInstance();
                $migrationService = new \App\Services\MigrationService(
                    $container->make(\Core\Redis::class),
                    $container->make(\Core\Database::class),
                    $container->make(\App\Contracts\LoggerInterface::class)
                );

                $migResult = $migrationService->runMigrations();

                if (empty($migResult['success'])) {
                    $errors[] = "اجرای مایگریشن‌ها با خطا مواجه شد: " . implode(', ', $migResult['errors'] ?? ['خطای ناشناخته']);
                } else {
                    // Create/Update Super Admin user directly with the entered credentials
                    try {
                        $pdo = $container->make(\Core\Database::class)->getPdo();
                        $adminEmail = $adminCreds['email'] ?? 'admin@chortke.ir';
                        $adminPass = $adminCreds['pass'] ?? '';
                        $hash = password_hash($adminPass, PASSWORD_ARGON2ID);

                        $pdo->exec("
                            INSERT INTO users (email, username, full_name, password, role, is_admin, status, kyc_status, email_verified_at, created_at, updated_at)
                            VALUES (" . $pdo->quote($adminEmail) . ", 'admin', 'مدیر ارشد سیستم', " . $pdo->quote($hash) . ", 'super_admin', 1, 'active', 'verified', NOW(), NOW(), NOW())
                            ON DUPLICATE KEY UPDATE password = " . $pdo->quote($hash) . ", role = 'super_admin', status = 'active', email_verified_at = NOW(), updated_at = NOW()
                        ");

                        $userRow = $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($adminEmail))->fetch(PDO::FETCH_OBJ);
                        if ($userRow && !empty($userRow->id)) {
                            $adminUserId = (int)$userRow->id;
                            $pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ({$adminUserId}, 3), ({$adminUserId}, 2)");
                        }

                        @file_put_contents(__DIR__.'/../../storage/installed.lock', date('c'));
                        $_SESSION['done'] = true;
                        $_SESSION['wm'] = $wm;
                        header("Location:?step=7");
                        exit;
                    } catch (Throwable $e) {
                        $errors[] = "خطا در ایجاد حساب مدیر ارشد: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

$reqs=[check_req('PHP≥8.2',version_compare(PHP_VERSION,'8.2.0','>=')),check_req('pdo_mysql',extension_loaded('pdo_mysql')),check_req('redis',extension_loaded('redis')),check_req('bcmath',extension_loaded('bcmath')),check_req('storage writable',is_writable(__DIR__.'/../../storage'))];
$reqOk=!in_array(false,array_column(array_filter($reqs,fn($r)=>$r['required']),'ok'));
?>
<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>نصب چرتکه v<?=INSTALLER_VERSION?></title>
<style>body{font-family:Tahoma;background:#f1f5f9;margin:0;padding:20px} .container{max-width:820px;margin:30px auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.1);overflow:hidden} .header{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;padding:28px} .steps{display:flex;background:#f8fafc;padding:10px 16px;gap:6px} .step{padding:5px 14px;border-radius:999px;font-size:12px;background:#e2e8f0} .step.active{background:#1e40af;color:#fff} .step.done{background:#10b981;color:#fff} .content{padding:32px} label{display:block;margin:8px 0 4px;font-weight:600} input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px} .btn{background:#1e40af;color:#fff;border:none;padding:12px 26px;border-radius:8px;font-size:15px;cursor:pointer} table{width:100%;margin:16px 0} td{padding:8px;border-bottom:1px solid #eee} .ok{color:#16a34a;font-weight:700} .fail{color:#dc2626;font-weight:700} .alert{padding:12px;border-radius:8px;margin:12px 0} .alert.error{background:#fee2e2;color:#991b1b}</style>
</head><body><div class="container"><div class="header"><h1>نصب امن چرتکه v<?=INSTALLER_VERSION?></h1><p>نصب‌کننده ایمن گرافیکی • ایجاد حساب مدیر ارشد واقعی با هش Argon2id</p></div>
<div class="steps"><?php for($i=1;$i<=7;$i++)echo '<span class="step '.($i<$step?'done':($i==$step?'active':'')).'">'.$i.'</span>'; ?></div>
<div class="content">
<?php if($errors)echo '<div class="alert error">'.implode('<br>',$errors).'</div>'; ?>

<?php if($step==1): ?>
<h2>بررسی پیش‌نیازها</h2>
<table><?php foreach($reqs as $r)echo '<tr><td>'.$r['name'].'</td><td>'.($r['ok']?'<span class="ok">✓</span>':'<span class="fail">✗</span>').'</td></tr>'; ?></table>
<?php if($reqOk): ?><a href="?step=2" class="btn">ادامه</a><?php else: ?><p style="color:#dc2626">پیش‌نیازهای الزامی را برطرف کنید.</p><?php endif; ?>

<?php elseif($step==2): ?>
<h2>دیتابیس</h2><form method="post">
<input type="hidden" name="_csrf_token" value="<?= e($_SESSION['_csrf_token']) ?>">
<input name="db_host" placeholder="هاست" value="127.0.0.1"><br><br>
<input name="db_port" placeholder="پورت" value="3306"><br><br>
<input name="db_name" placeholder="نام دیتابیس" value="chortke"><br><br>
<input name="db_user" placeholder="کاربر" value="root"><br><br>
<input name="db_pass" type="password" placeholder="رمز"><br><br>
<button class="btn">تست و ادامه</button></form>

<?php elseif($step==3): ?>
<h2>Redis و صف</h2><form method="post">
<input type="hidden" name="_csrf_token" value="<?= e($_SESSION['_csrf_token']) ?>">
<select name="queue_driver"><option value="redis">redis (پیشنهادی)</option><option value="database">database</option></select><br><br>
<input name="redis_host" value="127.0.0.1"><br><br>
<input name="redis_port" value="6379"><br><br>
<input name="redis_pass" type="password" placeholder="رمز ردیس (اختیاری)"><br><br>
<button class="btn">ادامه</button></form>

<?php elseif($step==4): ?>
<h2>تنظیمات + APIها</h2><form method="post">
<input type="hidden" name="_csrf_token" value="<?= e($_SESSION['_csrf_token']) ?>">
<input name="app_name" value="چرتکه"><br><br>
<input name="app_url" value="http://127.0.0.1:8080"><br><br>
<input name="zarinpal" placeholder="Zarinpal Merchant ID"><br><br>
<input name="kavenegar" placeholder="Kavenegar API Key"><br><br>
<button class="btn">ادامه</button></form>

<?php elseif($step==5): ?>
<h2>کاربر مدیر ارشد (Super Admin)</h2><form method="post">
<input type="hidden" name="_csrf_token" value="<?= e($_SESSION['_csrf_token']) ?>">
<input name="admin_email" value="admin@chortke.ir"><br><br>
<input name="admin_pass" type="password" placeholder="رمز ادمین (حداقل ۱۲ کاراکتر ترکیبی)"><br><br>
<p style="font-size:12px;color:#64748b">رمز عبور باید شامل حروف کوچک، بزرگ، اعداد و کاراکترهای ویژه (مانند !@#$) باشد.</p>
<button class="btn">ادامه</button></form>

<?php elseif($step==6): ?>
<h2>روش Workerها و اجرای مایگریشن</h2><form method="post">
<input type="hidden" name="_csrf_token" value="<?= e($_SESSION['_csrf_token']) ?>">
<select name="worker_method"><option value="supervisor">Supervisor</option><option value="systemd">systemd</option><option value="docker">Docker</option></select><br><br>
<button class="btn">نصب نهایی و ساخت حساب مدیر</button></form>

<?php elseif($step==7): ?>
<div class="alert" style="background:#dcfce7;color:#166534">
    <h2>نصب امن با موفقیت تکمیل شد!</h2>
    <p>حساب مدیر ارشد واقعی با رمزعبور واردشده (هش Argon2id) در دیتابیس ایجاد گردید.</p>
    <p><strong>هشدار مهم:</strong> پوشه <code>public/install</code> را فوراً حذف یا تغییر نام دهید.</p>
</div>
<pre>php cli.php distributed:health
php cli.php simulate:traceable-event --type=install.test --user=1</pre>
<a href="/login" class="btn">رفتن به صفحه ورود</a>
<?php endif; ?>
</div></div></body></html>
