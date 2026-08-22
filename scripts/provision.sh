#!/usr/bin/env bash
# =============================================================================
#  chortke — بازسازی کامل محیط توسعه از صفر
# =============================================================================
#  چرا این اسکریپت لازم است؟
#    در این سندباکس نه `apt` کار می‌کند و نه Docker. بنابراین PHP، MariaDB و
#    Redis باید از روی سورس کامپایل شوند. این اسکریپت تمام آن مراحل را
#    (به همراه همه‌ی دورزدن‌های کشف‌شده) خودکار می‌کند.
#
#  نحوه‌ی استفاده:
#      bash scripts/provision.sh            # اجرای کامل (تقریباً ۴۵ تا ۶۰ دقیقه)
#      bash scripts/provision.sh --status   # فقط نمایش وضعیت
#      bash scripts/provision.sh deps php   # اجرای مرحله‌های مشخص
#
#  مراحل: tools deps php redis phpredis mariadb project dbinit migrate serve
#  گزینه‌ها: --status  --start  --serve  --test  --force
#  هر مرحله idempotent است؛ اگر قبلاً انجام شده باشد رد می‌شود (--force برای اجبار).
# =============================================================================
set -uo pipefail

ROOT=/home/user
TOOLS=$ROOT/tools
DEPS=$TOOLS/deps
BUILD=$ROOT/build
RUNTIME=$ROOT/runtime
EXTRACT=$ROOT/extract
APP=$EXTRACT/workspace1e/chortke
ZIPFILE=$ROOT/zip/workspace1e.zip
JOBS="$(nproc)"
LOGDIR=/tmp/provision-logs
mkdir -p "$LOGDIR"

# نسخه‌های سنجاق‌شده (همگی تست‌شده و سازگار)
V_PHP=php-8.3.33
V_MARIADB=mariadb-11.4.4
V_REDIS=7.2.5
V_PHPREDIS=6.0.2
V_ZLIB=v1.3.1
V_OPENSSL=openssl-3.0.15
V_ONIG=v6.9.9
V_XML2=v2.12.9
V_CURL=curl-8_8_0
V_PNG=v1.6.43
V_JPEG=3.0.3
V_FREETYPE=VER-2-13-2
V_LIBZIP=v1.10.1
V_NCURSES=v6.4
V_PCRE2=pcre2-10.44
V_FMT=11.0.2          # مهم: fmt 12 با MariaDB ناسازگار است

DB_NAME=chortk
DB_TEST=chortk_test
DB_USER=chortke
DB_PASS=chortke_dev

RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; BLU=$'\e[34m'; DIM=$'\e[2m'; RST=$'\e[0m'
say()  { echo "${BLU}==>${RST} $*"; }
ok()   { echo "${GRN}  ✓${RST} $*"; }
warn() { echo "${YLW}  !${RST} $*"; }
die()  { echo "${RED}  ✗${RST} $*" >&2; exit 1; }
run()  { local log="$LOGDIR/$1.log"; shift; if "$@" >>"$log" 2>&1; then return 0; else
         echo "${RED}  ✗ شکست — ۳۰ خط آخر $log:${RST}" >&2; tail -30 "$log" >&2; return 1; fi; }

FORCE=0
STAGES_ALL=(tools deps php openssl_conf redis phpredis mariadb project dbinit migrate)

# -----------------------------------------------------------------------------
# محیط ساخت
# -----------------------------------------------------------------------------
write_env() {
  cat > "$TOOLS/env.sh" <<EOF
# source /home/user/tools/env.sh
export PATH=$TOOLS/venv/bin:$TOOLS/phpsrc/bin:$TOOLS/mariadb/bin:$TOOLS/redis/bin:\$PATH
export DEPS=$DEPS
export APP=$APP
export PKG_CONFIG_PATH=$DEPS/lib/pkgconfig:$DEPS/lib64/pkgconfig
# bison بسته‌ی PyPI مسیر /usr/bin/m4 را هاردکد کرده؛ بدون این متغیر کار نمی‌کند
export M4=$TOOLS/venv/bin/m4
# autoconf از ویل PyPI می‌آید و مسیرهای داخلی‌اش باید بازنویسی شود
export PERL5LIB=$TOOLS/venv/share/autoconf
export AC_MACRODIR=$TOOLS/venv/share/autoconf
export autom4te_perllibdir=$TOOLS/venv/share/autoconf
export AUTOM4TE_CFG=$TOOLS/venv/share/autoconf/autom4te.cfg
export AUTOM4TE=$TOOLS/venv/bin/autom4te
export AUTOCONF=$TOOLS/venv/bin/autoconf
export AUTOHEADER=$TOOLS/venv/bin/autoheader
EOF
}
load_env() { write_env; . "$TOOLS/env.sh"; }

gclone() { # gclone <dir> <url> <tag>
  local d=$1 u=$2 t=$3
  [ -d "$d/.git" ] && { ok "سورس موجود: $(basename "$d")"; return 0; }
  rm -rf "$d"
  git clone -q --depth 1 --branch "$t" "$u" "$d" || die "clone ناموفق: $u@$t"
  ok "دریافت شد: $(basename "$d") @ $t"
}

# ویل‌های PyPI حاوی باینری‌های C — تنها راه گرفتن m4/autoconf بدون apt
pypi_extract() { # pypi_extract <package> <dest-prefix>
  local pkg=$1 dest=$2 tmp; tmp=$(mktemp -d)
  "$TOOLS/venv/bin/pip" download -q --no-deps --only-binary=:all: "$pkg" -d "$tmp" >/dev/null 2>&1 \
    || { rm -rf "$tmp"; return 1; }
  "$TOOLS/venv/bin/python" - "$tmp" "$dest" <<'PY'
import sys, os, glob, zipfile
tmp, dest = sys.argv[1], sys.argv[2]
whl = glob.glob(os.path.join(tmp, '*.whl'))[0]
z = zipfile.ZipFile(whl); n = 0
for m in z.namelist():
    if 'cmeel.prefix/' not in m or m.endswith('/'):
        continue
    rel = m.split('cmeel.prefix/', 1)[1]
    out = os.path.join(dest, rel)
    os.makedirs(os.path.dirname(out), exist_ok=True)
    open(out, 'wb').write(z.open(m).read())
    if os.sep + 'bin' + os.sep in out:
        os.chmod(out, 0o755)
    n += 1
print('extracted', n)
PY
  rm -rf "$tmp"
}

# =============================================================================
# مرحله ۱ — ابزارهای پایه (python venv, cmake, ninja, m4, autoconf)
# =============================================================================
stage_tools() {
  say "مرحله ۱/۹ — ابزارهای ساخت"
  mkdir -p "$TOOLS" "$BUILD" "$DEPS"
  if [ ! -x "$TOOLS/venv/bin/python" ]; then
    python3 -m venv "$TOOLS/venv" || die "ساخت venv ناموفق"
  fi
  "$TOOLS/venv/bin/pip" install -q --upgrade pip >/dev/null 2>&1
  "$TOOLS/venv/bin/pip" install -q cmake ninja re2c bison-bin pkgconf >/dev/null 2>&1 \
    || die "نصب ابزارهای PyPI ناموفق"
  ok "cmake / ninja / re2c / bison / pkgconf"

  # GNU m4 — روی PyPI به صورت ویل cmeel موجود است (نه در apt که مسدود است)
  if [ ! -x "$TOOLS/venv/bin/m4" ]; then
    pypi_extract cmeel-m4 "$TOOLS/venv" >/dev/null || die "دریافت m4 ناموفق"
  fi
  "$TOOLS/venv/bin/m4" --version >/dev/null 2>&1 || die "m4 کار نمی‌کند"
  ok "GNU m4 $("$TOOLS/venv/bin/m4" --version | head -1 | grep -oE '[0-9.]+$')"

  # autoconf — لازم برای buildconf در PHP و phpize در افزونه‌ها
  if [ ! -f "$TOOLS/venv/share/autoconf/m4sugar/m4sugar.m4" ]; then
    pypi_extract cmeel-autoconf "$TOOLS/venv" >/dev/null || die "دریافت autoconf ناموفق"
  fi
  # ویل، مسیر موقتِ زمانِ ساختِ خودش را هاردکد کرده؛ باید بازنویسی شود
  local stale
  stale=$(grep -rhoE '/tmp/cmeel-[a-z0-9]+/whl/cmeel\.prefix' \
            "$TOOLS/venv/bin" "$TOOLS/venv/share/autoconf" 2>/dev/null | head -1)
  if [ -n "$stale" ]; then
    grep -rlF "$stale" "$TOOLS/venv/bin" "$TOOLS/venv/share/autoconf" 2>/dev/null \
      | xargs -r sed -i "s|$stale|$TOOLS/venv|g"
    ok "مسیرهای هاردکدشده‌ی autoconf اصلاح شد"
  fi
  load_env
  autoconf --version >/dev/null 2>&1 || die "autoconf کار نمی‌کند"
  ok "GNU autoconf $(autoconf --version | head -1 | grep -oE '[0-9.]+$')"
}

# =============================================================================
# مرحله ۲ — کتابخانه‌های C
# =============================================================================
stage_deps() {
  say "مرحله ۲/۹ — کتابخانه‌های سیستمی (static)"
  load_env
  mkdir -p "$BUILD/deps"
  local CM="-DCMAKE_INSTALL_PREFIX=$DEPS -DBUILD_SHARED_LIBS=OFF \
            -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DCMAKE_INSTALL_LIBDIR=lib \
            -DCMAKE_POLICY_VERSION_MINIMUM=3.5 -DCMAKE_PREFIX_PATH=$DEPS"

  gclone "$BUILD/deps/zlib"      https://github.com/madler/zlib.git              $V_ZLIB
  gclone "$BUILD/deps/openssl"   https://github.com/openssl/openssl.git          $V_OPENSSL
  gclone "$BUILD/deps/oniguruma" https://github.com/kkos/oniguruma.git           $V_ONIG
  gclone "$BUILD/deps/libxml2"   https://github.com/GNOME/libxml2.git            $V_XML2
  gclone "$BUILD/deps/curl"      https://github.com/curl/curl.git                $V_CURL
  gclone "$BUILD/deps/libpng"    https://github.com/pnggroup/libpng.git          $V_PNG
  gclone "$BUILD/deps/libjpeg"   https://github.com/libjpeg-turbo/libjpeg-turbo.git $V_JPEG
  gclone "$BUILD/deps/freetype"  https://github.com/freetype/freetype.git        $V_FREETYPE
  gclone "$BUILD/deps/libzip"    https://github.com/nih-at/libzip.git            $V_LIBZIP
  gclone "$BUILD/deps/ncurses"   https://github.com/mirror/ncurses.git           $V_NCURSES
  gclone "$BUILD/deps/pcre2"     https://github.com/PCRE2Project/pcre2.git       $V_PCRE2
  gclone "$BUILD/deps/fmt"       https://github.com/fmtlib/fmt.git               $V_FMT

  if [ ! -f "$DEPS/lib/libz.a" ]; then
    ( cd "$BUILD/deps/zlib" && ./configure --prefix="$DEPS" --static && make -j"$JOBS" && make install ) \
      >"$LOGDIR/zlib.log" 2>&1 || die "zlib"
  fi; ok "zlib"

  if [ ! -f "$DEPS/lib64/libssl.a" ] && [ ! -f "$DEPS/lib/libssl.a" ]; then
    ( cd "$BUILD/deps/openssl" \
      && ./Configure --prefix="$DEPS" --openssldir="$DEPS/ssl" no-shared no-tests linux-x86_64 \
      && make -j"$JOBS" && make install_sw ) >"$LOGDIR/openssl.log" 2>&1 || die "openssl"
  fi; ok "openssl"

  # oniguruma با CMake ساخته می‌شود (مسیر autotools نیاز به autoreconf دارد)
  if [ ! -f "$DEPS/lib/libonig.a" ]; then
    ( cd "$BUILD/deps/oniguruma" && cmake -S . -B b $CM && cmake --build b -j"$JOBS" && cmake --install b ) \
      >"$LOGDIR/onig.log" 2>&1 || die "oniguruma"
  fi; ok "oniguruma"

  if [ ! -f "$DEPS/lib/libxml2.a" ]; then
    ( cd "$BUILD/deps/libxml2" && cmake -S . -B b $CM -DLIBXML2_WITH_PYTHON=OFF \
        -DLIBXML2_WITH_LZMA=OFF -DLIBXML2_WITH_ICONV=OFF -DLIBXML2_WITH_TESTS=OFF \
        -DLIBXML2_WITH_PROGRAMS=OFF -DLIBXML2_WITH_ZLIB=ON \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/xml.log" 2>&1 || die "libxml2"
  fi; ok "libxml2"

  if [ ! -f "$DEPS/lib/libpng16.a" ]; then
    ( cd "$BUILD/deps/libpng" && cmake -S . -B b $CM -DPNG_SHARED=OFF -DPNG_TESTS=OFF \
        -DPNG_TOOLS=OFF -DZLIB_ROOT="$DEPS" \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/png.log" 2>&1 || die "libpng"
  fi; ok "libpng"

  if [ ! -f "$DEPS/lib/libjpeg.a" ]; then
    ( cd "$BUILD/deps/libjpeg" && cmake -S . -B b $CM -DENABLE_SHARED=OFF -DENABLE_STATIC=ON \
        -DWITH_TURBOJPEG=OFF && cmake --build b -j"$JOBS" && cmake --install b ) \
      >"$LOGDIR/jpg.log" 2>&1 || die "libjpeg-turbo"
  fi; ok "libjpeg-turbo"

  if [ ! -f "$DEPS/lib/libfreetype.a" ]; then
    ( cd "$BUILD/deps/freetype" && cmake -S . -B b $CM -DFT_DISABLE_HARFBUZZ=ON \
        -DFT_DISABLE_BROTLI=ON -DFT_DISABLE_BZIP2=ON -DFT_REQUIRE_ZLIB=ON \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/ft.log" 2>&1 || die "freetype"
  fi; ok "freetype"

  if [ ! -f "$DEPS/lib/libzip.a" ]; then
    ( cd "$BUILD/deps/libzip" && cmake -S . -B b $CM -DENABLE_GNUTLS=OFF -DENABLE_MBEDTLS=OFF \
        -DENABLE_OPENSSL=OFF -DENABLE_BZIP2=OFF -DENABLE_LZMA=OFF -DENABLE_ZSTD=OFF \
        -DBUILD_TOOLS=OFF -DBUILD_REGRESS=OFF -DBUILD_EXAMPLES=OFF -DBUILD_DOC=OFF \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/zip.log" 2>&1 || die "libzip"
  fi; ok "libzip"

  if [ ! -f "$DEPS/lib/libcurl.a" ]; then
    ( cd "$BUILD/deps/curl" && cmake -S . -B b $CM -DBUILD_CURL_EXE=OFF -DCURL_USE_OPENSSL=ON \
        -DOPENSSL_ROOT_DIR="$DEPS" -DCURL_ZLIB=ON -DCURL_USE_LIBSSH2=OFF -DCURL_USE_LIBPSL=OFF \
        -DUSE_NGHTTP2=OFF -DCURL_BROTLI=OFF -DCURL_ZSTD=OFF -DBUILD_TESTING=OFF \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/curl.log" 2>&1 || die "curl"
  fi; ok "curl"

  if [ ! -f "$DEPS/lib/libpcre2-8.a" ]; then
    ( cd "$BUILD/deps/pcre2" && cmake -S . -B b $CM -DPCRE2_BUILD_TESTS=OFF \
        -DPCRE2_BUILD_PCRE2GREP=OFF -DPCRE2_SUPPORT_JIT=ON \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/pcre2.log" 2>&1 || die "pcre2"
  fi; ok "pcre2"

  # fmt 11 — نسخه ۱۲ ماکرو FMT_STATIC_THOUSANDS_SEPARATOR را حذف کرده و
  # تشخیص خودکار MariaDB شکست می‌خورد
  if [ ! -f "$DEPS/lib/libfmt.a" ]; then
    ( cd "$BUILD/deps/fmt" && cmake -S . -B b $CM -DFMT_TEST=OFF -DFMT_DOC=OFF \
      && cmake --build b -j"$JOBS" && cmake --install b ) >"$LOGDIR/fmt.log" 2>&1 || die "fmt"
  fi; ok "fmt $V_FMT"

  # ncurses: با --with-termlib ساخته می‌شود، پس tputs/tgoto در libtinfow.a قرار
  # می‌گیرند. لینکر MariaDB فقط یک آرشیو می‌پذیرد → دو آرشیو را ادغام می‌کنیم.
  if [ ! -f "$DEPS/lib/libncursesw.a" ]; then
    ( cd "$BUILD/deps/ncurses" && ./configure --prefix="$DEPS" --without-shared --with-normal \
        --without-debug --without-ada --without-tests --without-manpages --enable-widec --with-termlib \
      && make -j"$JOBS" && make install ) >"$LOGDIR/ncurses.log" 2>&1 || die "ncurses"
  fi
  if [ ! -f "$DEPS/lib/libncursesw_full.a" ]; then
    printf 'create %s/lib/libncursesw_full.a\naddlib %s/lib/libncursesw.a\naddlib %s/lib/libtinfow.a\nsave\nend\n' \
      "$DEPS" "$DEPS" "$DEPS" | ar -M || die "ادغام ncurses"
  fi; ok "ncurses (+ آرشیو ادغام‌شده)"

  # تله‌ی مسیر هدرها: libxml2/freetype/png در زیرپوشه نصب می‌شوند
  ln -sfn "$DEPS/include/libxml2/libxml"    "$DEPS/include/libxml"     2>/dev/null
  ln -sfn "$DEPS/include/freetype2/freetype" "$DEPS/include/freetype"  2>/dev/null
  ln -sfn "$DEPS/include/freetype2/ft2build.h" "$DEPS/include/ft2build.h" 2>/dev/null
  ln -sfn "$DEPS/include/libpng16/png.h"    "$DEPS/include/png.h"      2>/dev/null
  # کلاینت CLI مربوط به MariaDB مستقیماً <curses.h> و <term.h> را include
  # می‌کند، ولی نسخه‌ی wide آنها در زیرپوشه‌ی ncursesw نصب می‌شود
  for h in curses.h ncurses.h term.h termcap.h unctrl.h ncurses_dll.h \
           eti.h form.h menu.h panel.h nc_tparm.h; do
    [ -f "$DEPS/include/ncursesw/$h" ] && ln -sfn "$DEPS/include/ncursesw/$h" "$DEPS/include/$h"
  done
  ok "symlink هدرها"
}

# =============================================================================
# مرحله ۳ — PHP
# =============================================================================
stage_php() {
  say "مرحله ۳/۹ — PHP ($V_PHP)"
  load_env
  [ -x "$TOOLS/phpsrc/bin/php" ] && [ "$FORCE" = 0 ] && { ok "از قبل نصب است: $("$TOOLS/phpsrc/bin/php" -r 'echo PHP_VERSION;')"; return 0; }
  gclone "$BUILD/php-src" https://github.com/php/php-src.git $V_PHP
  cd "$BUILD/php-src"
  [ -f configure ] || run php-buildconf ./buildconf --force || die "buildconf"
  export CPPFLAGS="-I$DEPS/include"
  export LDFLAGS="-L$DEPS/lib -L$DEPS/lib64"
  export LIBS="-lssl -lcrypto -lz -lpthread -ldl"
  if [ ! -f Makefile ]; then
    run php-configure ./configure --prefix="$TOOLS/phpsrc" \
      --enable-cli --disable-cgi --disable-phpdbg \
      --with-pdo-mysql=mysqlnd --with-mysqli=mysqlnd \
      --enable-mbstring --enable-bcmath --enable-sockets --enable-pcntl --enable-opcache \
      --with-openssl --with-zlib --with-libxml --enable-dom --enable-simplexml --enable-xml \
      --with-curl --with-zip --enable-gd --with-jpeg --with-freetype --enable-exif \
      --without-sqlite3 --without-pdo-sqlite --without-pear || die "configure PHP"
  fi
  # تله‌ی مهم: تنظیم دستی LIBS در بالا، تشخیص خودکار configure را بازنویسی
  # می‌کند و libxml2/curl/zip/png/jpeg/freetype/onig از خط لینک حذف می‌شوند
  # (هزاران خطای «undefined reference to xmlFree»). لیست را کامل می‌کنیم.
  sed -i 's|^EXTRA_LIBS = .*|EXTRA_LIBS = -lrt -lm -lxml2 -lcurl -lzip -lpng16 -ljpeg -lfreetype -lonig -lssl -lcrypto -lz -lpthread -ldl|' Makefile
  run php-make make -j"$JOBS" || die "کامپایل PHP"
  run php-make make install || die "نصب PHP"
  mkdir -p "$TOOLS/phpsrc/lib"
  cat > "$TOOLS/phpsrc/lib/php.ini" <<EOF
memory_limit = 512M
max_execution_time = 120
date.timezone = Asia/Tehran
extension_dir = "$TOOLS/phpsrc/lib/php/extensions/no-debug-non-zts-20230831"
extension=redis.so
opcache.enable_cli = 0
error_reporting = E_ALL
display_errors = On
EOF
  ok "PHP $("$TOOLS/phpsrc/bin/php" -r 'echo PHP_VERSION;')"
}

# =============================================================================
# مرحله ۴ — Redis
# =============================================================================
stage_redis() {
  say "مرحله ۴/۹ — Redis $V_REDIS"
  load_env
  if [ ! -x "$TOOLS/redis/bin/redis-server" ] || [ "$FORCE" = 1 ]; then
    gclone "$BUILD/redis-src" https://github.com/redis/redis.git $V_REDIS
    # USE_SYSTEMD=no الزامی است: پیکربندی خودکار Redis وجود systemd را حدس می‌زند
    # و اگر هدرهای systemd/sd-daemon.h نباشند، ساخت با fatal error می‌شکند.
    ( cd "$BUILD/redis-src" && make -j"$JOBS" MALLOC=libc BUILD_TLS=no USE_SYSTEMD=no \
      && make install PREFIX="$TOOLS/redis" USE_SYSTEMD=no ) >"$LOGDIR/redis.log" 2>&1 || die "redis"
  fi
  mkdir -p "$RUNTIME/redis/data"
  cat > "$RUNTIME/redis/redis.conf" <<EOF
bind 127.0.0.1
port 6379
daemonize no
dir $RUNTIME/redis/data
save 900 1
appendonly no
EOF
  ok "Redis $("$TOOLS/redis/bin/redis-server" --version | grep -oE 'v=[0-9.]+' | cut -d= -f2)"
}

# =============================================================================
# مرحله ۵ — افزونه phpredis
# =============================================================================
stage_phpredis() {
  say "مرحله ۵/۹ — افزونه phpredis $V_PHPREDIS"
  load_env
  local extdir="$TOOLS/phpsrc/lib/php/extensions/no-debug-non-zts-20230831"
  if [ -f "$extdir/redis.so" ] && [ "$FORCE" = 0 ]; then ok "از قبل ساخته شده"; return 0; fi
  gclone "$BUILD/phpredis" https://github.com/phpredis/phpredis.git $V_PHPREDIS
  ( cd "$BUILD/phpredis" && "$TOOLS/phpsrc/bin/phpize" \
    && ./configure --with-php-config="$TOOLS/phpsrc/bin/php-config" \
    && make -j"$JOBS" && make install ) >"$LOGDIR/phpredis.log" 2>&1 || die "phpredis"
  "$TOOLS/phpsrc/bin/php" -m 2>/dev/null | grep -qi '^redis$' || die "افزونه redis بارگذاری نشد"
  ok "phpredis بارگذاری شد"
}

# =============================================================================
# مرحله ۶ — MariaDB
# =============================================================================
stage_mariadb() {
  say "مرحله ۶/۹ — MariaDB ($V_MARIADB) — طولانی‌ترین مرحله، حدود ۳۰ دقیقه"
  load_env
  [ -x "$TOOLS/mariadb/bin/mariadbd" ] && [ "$FORCE" = 0 ] && { ok "از قبل نصب است"; return 0; }
  gclone "$BUILD/mariadb-src" https://github.com/MariaDB/server.git $V_MARIADB
  ( cd "$BUILD/mariadb-src" && git submodule update --init --depth 1 \
      libmariadb wsrep-lib storage/maria/libmarias3 ) >>"$LOGDIR/mariadb.log" 2>&1
  cd "$BUILD/mariadb-src"
  if [ ! -f build/build.ninja ]; then
    run mariadb-configure cmake -S . -B build -G Ninja \
      -DCMAKE_BUILD_TYPE=RelWithDebInfo -DCMAKE_INSTALL_PREFIX="$TOOLS/mariadb" \
      -DCMAKE_POLICY_VERSION_MINIMUM=3.5 \
      -DCMAKE_C_FLAGS="-I$DEPS/include" -DCMAKE_CXX_FLAGS="-I$DEPS/include" \
      -DCMAKE_PREFIX_PATH="$DEPS" -DCMAKE_LIBRARY_PATH="$DEPS/lib" \
      -DCMAKE_EXE_LINKER_FLAGS="-L$DEPS/lib -L$DEPS/lib64" \
      -DCMAKE_SHARED_LINKER_FLAGS="-L$DEPS/lib -L$DEPS/lib64" \
      -DCMAKE_MODULE_LINKER_FLAGS="-L$DEPS/lib -L$DEPS/lib64" \
      -DWITH_LIBFMT=system -DLIBFMT_INCLUDE_DIR="$DEPS/include" -DWITH_PCRE=system \
      -DCURSES_LIBRARY="$DEPS/lib/libncursesw_full.a" \
      -DCURSES_INCLUDE_PATH="$DEPS/include/ncursesw" -DCURSES_NEED_WIDE=TRUE \
      -DCURSES_HAVE_CURSES_H="$DEPS/include/curses.h" \
      -DCURSES_CURSES_H_PATH="$DEPS/include" \
      -DWITH_SSL=system -DOPENSSL_ROOT_DIR="$DEPS" -DOPENSSL_USE_STATIC_LIBS=TRUE \
      -DWITH_ZLIB=bundled -DAUTH_GSSAPI=OFF -DPLUGIN_AUTH_GSSAPI=NO \
      -DPLUGIN_HASHICORP_KEY_MANAGEMENT=NO \
      -DPLUGIN_TOKUDB=NO -DPLUGIN_ROCKSDB=NO -DPLUGIN_MROONGA=NO -DPLUGIN_SPIDER=NO \
      -DPLUGIN_OQGRAPH=NO -DPLUGIN_PERFSCHEMA=NO -DPLUGIN_SPHINX=NO -DPLUGIN_CONNECT=NO \
      -DPLUGIN_COLUMNSTORE=NO -DPLUGIN_S3=NO \
      -DWITH_WSREP=OFF -DWITH_MARIABACKUP=OFF -DWITH_UNIT_TESTS=OFF \
      -DWITH_EMBEDDED_SERVER=OFF -DSKIP_TESTS=ON -DWITH_NUMA=OFF -DWITH_JEMALLOC=NO \
      || die "پیکربندی MariaDB"
  fi
  run mariadb-build cmake --build build -j"$JOBS" || die "کامپایل MariaDB"
  run mariadb-build cmake --install build || die "نصب MariaDB"
  ok "$("$TOOLS/mariadb/bin/mariadbd" --version | head -1)"
}

# -----------------------------------------------------------------------------
# فایل پیکربندی OpenSSL
#
# PHP ساخته‌شده در این سندباکس به «$TOOLS/deps/ssl/openssl.cnf» اشاره می‌کند
# (خروجی «Openssl default config» در php -i). اگر این فایل وجود نداشته باشد،
# openssl_pkey_new() با خطای «configuration file routines::no such file»
# مقدار false برمی‌گرداند و هر تستی که کلید RSA تولید می‌کند شکست می‌خورد:
#   • قرارداد JWT/JWKS گوگل
#   • OAuth سرویس‌اکانت FCM
# این نقصِ محیط است، نه نقصِ کدِ پروژه؛ بنابراین اینجا ساخته می‌شود نه در سورس.
# -----------------------------------------------------------------------------
stage_openssl_conf() {
  local cnf="$TOOLS/deps/ssl/openssl.cnf"
  [ -f "$cnf" ] && { ok "openssl.cnf از قبل موجود است"; return 0; }
  mkdir -p "$(dirname "$cnf")"
  cat > "$cnf" <<'OPENSSLCNF'
openssl_conf = default_conf

[ default_conf ]
ssl_conf = ssl_sect

[ ssl_sect ]
system_default = system_default_sect

[ system_default_sect ]
MinProtocol = TLSv1.2
CipherString = DEFAULT:@SECLEVEL=1

[ req ]
default_bits       = 2048
default_md         = sha256
distinguished_name = req_distinguished_name
prompt             = no

[ req_distinguished_name ]
CN = chortke-local
OPENSSLCNF
  ok "openssl.cnf ساخته شد: $cnf"
}

# =============================================================================
# مرحله ۷ — سورس پروژه و وابستگی‌های Composer
# =============================================================================
stage_project() {
  say "مرحله ۷/۹ — سورس پروژه + Composer"
  load_env
  if [ ! -d "$APP" ]; then
    [ -f "$ZIPFILE" ] || die "فایل زیپ پیدا نشد: $ZIPFILE"
    mkdir -p "$EXTRACT" && ( cd "$EXTRACT" && unzip -q -o "$ZIPFILE" ) || die "استخراج زیپ"
  fi
  ok "سورس پروژه در $APP"
  if [ ! -d "$APP/vendor" ] || [ "$FORCE" = 1 ]; then
    mkdir -p "$TOOLS/composer-home"
    ( cd "$APP" && COMPOSER_HOME="$TOOLS/composer-home" \
      "$TOOLS/phpsrc/bin/php" composer.phar install \
        --no-interaction --no-progress --no-scripts --prefer-dist ) \
      >"$LOGDIR/composer.log" 2>&1 || die "composer install"
  fi
  ok "وابستگی‌های Composer ($(ls "$APP/vendor" | wc -l) پوشه)"
}

# =============================================================================
# مرحله ۸ — راه‌اندازی دیتابیس
# =============================================================================
stage_dbinit() {
  say "مرحله ۸/۹ — راه‌اندازی دیتابیس"
  load_env
  mkdir -p "$RUNTIME/mariadb"/{data,log,run,tmp}
  # نکته: چون با PLUGIN_PERFSCHEMA=NO ساخته‌ایم، وجود performance_schema در
  # این فایل باعث خطای «unknown variable» و بالا نیامدن سرور می‌شود.
  cat > "$RUNTIME/mariadb/my.cnf" <<EOF
[mysqld]
basedir     = $TOOLS/mariadb
datadir     = $RUNTIME/mariadb/data
socket      = $RUNTIME/mariadb/run/mysql.sock
pid-file    = $RUNTIME/mariadb/run/mysqld.pid
tmpdir      = $RUNTIME/mariadb/tmp
log-error   = $RUNTIME/mariadb/log/error.log
bind-address = 127.0.0.1
port        = 3306
skip-name-resolve
character-set-server  = utf8mb4
collation-server      = utf8mb4_unicode_ci
innodb_buffer_pool_size = 256M
innodb_log_file_size    = 64M
max_connections         = 100

[client]
socket = $RUNTIME/mariadb/run/mysql.sock
EOF
  if [ ! -d "$RUNTIME/mariadb/data/mysql" ]; then
    "$TOOLS/mariadb/scripts/mariadb-install-db" \
      --defaults-file="$RUNTIME/mariadb/my.cnf" --basedir="$TOOLS/mariadb" \
      --datadir="$RUNTIME/mariadb/data" --user="$(whoami)" \
      --auth-root-authentication-method=normal >"$LOGDIR/dbinit.log" 2>&1 || die "mariadb-install-db"
    ok "دیتادایرکتوری ساخته شد"
  fi
  start_mariadb
  "$TOOLS/mariadb/bin/mariadb" --socket="$RUNTIME/mariadb/run/mysql.sock" -u root <<SQL || die "ساخت دیتابیس"
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`$DB_TEST\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'%'         IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost', '$DB_USER'@'127.0.0.1', '$DB_USER'@'%';
GRANT ALL PRIVILEGES ON \`$DB_TEST\`.* TO '$DB_USER'@'localhost', '$DB_USER'@'127.0.0.1', '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL
  ok "دیتابیس‌های $DB_NAME و $DB_TEST + کاربر $DB_USER"
}

start_mariadb() {
  if "$TOOLS/mariadb/bin/mariadb" --socket="$RUNTIME/mariadb/run/mysql.sock" -u root -e "SELECT 1" >/dev/null 2>&1; then
    return 0
  fi
  nohup "$TOOLS/mariadb/bin/mariadbd" --defaults-file="$RUNTIME/mariadb/my.cnf" \
    --user="$(whoami)" >>"$RUNTIME/mariadb/log/stdout.log" 2>&1 &
  for _ in $(seq 1 60); do
    "$TOOLS/mariadb/bin/mariadb" --socket="$RUNTIME/mariadb/run/mysql.sock" -u root -e "SELECT 1" >/dev/null 2>&1 && { ok "MariaDB بالا آمد"; return 0; }
    sleep 1
  done
  die "MariaDB بالا نیامد — $RUNTIME/mariadb/log/error.log را ببینید"
}

start_redis() {
  "$TOOLS/redis/bin/redis-cli" -h 127.0.0.1 ping >/dev/null 2>&1 && return 0
  mkdir -p "$RUNTIME/redis/data"
  [ -f "$RUNTIME/redis/redis.conf" ] || printf 'bind 127.0.0.1\nport 6379\ndaemonize no\ndir %s\nsave 900 1\nappendonly no\n' "$RUNTIME/redis/data" > "$RUNTIME/redis/redis.conf"
  nohup "$TOOLS/redis/bin/redis-server" "$RUNTIME/redis/redis.conf" \
    >>"$RUNTIME/redis/redis.log" 2>&1 &
  for _ in $(seq 1 30); do
    "$TOOLS/redis/bin/redis-cli" -h 127.0.0.1 ping >/dev/null 2>&1 && { ok "Redis بالا آمد"; return 0; }
    sleep 1
  done
  die "Redis بالا نیامد"
}

# =============================================================================
# مرحله ۹ — اجرای مهاجرت‌ها
# =============================================================================
stage_migrate() {
  say "مرحله ۹/۹ — اجرای مهاجرت‌ها"
  load_env
  start_redis
  start_mariadb
  ( cd "$APP" && "$TOOLS/phpsrc/bin/php" migrate.php ) 2>&1 | tail -5
  local n
  n=$("$TOOLS/mariadb/bin/mariadb" -h127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null)
  ok "تعداد جداول: $n"
}

serve() {
  load_env; start_redis; start_mariadb; link_php
  say "بالا آوردن وب‌سرور روی 0.0.0.0:8080"
  cd "$APP" && exec "$TOOLS/phpsrc/bin/php" -S 0.0.0.0:8080 -t public public/index.php
}

# -----------------------------------------------------------------------------
# php را در PATH سراسری قرار می‌دهد.
# تست‌های Integration/Distributed با shell_exec("php cli.php ...") اجرا می‌شوند
# و به باینری php در PATH نیاز دارند؛ بدون این symlink آن‌ها fail می‌کنند.
# -----------------------------------------------------------------------------
link_php() {
  [ -x "$TOOLS/phpsrc/bin/php" ] || return 0
  if [ ! -e /usr/local/bin/php ] || [ "$(readlink -f /usr/local/bin/php)" != "$TOOLS/phpsrc/bin/php" ]; then
    ln -sf "$TOOLS/phpsrc/bin/php" /usr/local/bin/php 2>/dev/null \
      && ok "php در /usr/local/bin/php لینک شد" \
      || warn "امکان ساخت symlink در /usr/local/bin نبود — PATH را دستی تنظیم کنید"
  fi
}

# -----------------------------------------------------------------------------
# وب‌سرور تست روی پورت 8090 (پیش‌نیاز tests/Integration/Distributed)
# HealthEndpointsTest و MetricsTest صریحاً 127.0.0.1:8090 را صدا می‌زنند.
# -----------------------------------------------------------------------------
start_test_server() {
  curl -s -o /dev/null -m 2 http://127.0.0.1:8090/ 2>/dev/null && return 0
  mkdir -p "$RUNTIME/web"
  ( cd "$APP" && nohup "$TOOLS/phpsrc/bin/php" -S 0.0.0.0:8090 -t public public/index.php \
      >>"$RUNTIME/web/test-8090.log" 2>&1 & )
  for _ in $(seq 1 20); do
    curl -s -o /dev/null -m 2 http://127.0.0.1:8090/ 2>/dev/null && { ok "وب‌سرور تست روی 8090 بالا آمد"; return 0; }
    sleep 1
  done
  warn "وب‌سرور تست 8090 بالا نیامد"
}

# -----------------------------------------------------------------------------
# اجرای کامل سوئیت تست با تمام پیش‌نیازهای محیطی
#   bash scripts/provision.sh --test
# -----------------------------------------------------------------------------
run_tests() {
  load_env; start_redis; start_mariadb; link_php; start_test_server
  say "اجرای PHPUnit"
  cd "$APP" && PATH="$TOOLS/phpsrc/bin:$PATH" "$TOOLS/phpsrc/bin/php" vendor/bin/phpunit --no-coverage "$@"
}

status() {
  echo "${BLU}=== وضعیت محیط ===${RST}"
  printf "  %-14s " "PHP";     [ -x "$TOOLS/phpsrc/bin/php" ] && "$TOOLS/phpsrc/bin/php" -r 'echo PHP_VERSION,"\n";' || echo "${RED}نصب نشده${RST}"
  printf "  %-14s " "MariaDB"; [ -x "$TOOLS/mariadb/bin/mariadbd" ] && "$TOOLS/mariadb/bin/mariadbd" --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+-MariaDB' | head -1 || echo "${RED}نصب نشده${RST}"
  printf "  %-14s " "Redis";   [ -x "$TOOLS/redis/bin/redis-server" ] && "$TOOLS/redis/bin/redis-server" --version | grep -oE 'v=[0-9.]+' || echo "${RED}نصب نشده${RST}"
  printf "  %-14s " "پروژه";   [ -d "$APP/vendor" ] && echo "vendor آماده" || echo "${YLW}vendor نصب نشده${RST}"
  echo "${BLU}=== سرویس‌های در حال اجرا ===${RST}"
  printf "  %-14s " "MariaDB"; "$TOOLS/mariadb/bin/mariadb" --socket="$RUNTIME/mariadb/run/mysql.sock" -u root -e "SELECT 1" >/dev/null 2>&1 && echo "${GRN}بالا${RST}" || echo "${DIM}پایین${RST}"
  printf "  %-14s " "Redis";   "$TOOLS/redis/bin/redis-cli" -h 127.0.0.1 ping >/dev/null 2>&1 && echo "${GRN}بالا${RST}" || echo "${DIM}پایین${RST}"
  printf "  %-14s " "وب:8080"; curl -s -o /dev/null -m 3 -w '%{http_code}\n' http://127.0.0.1:8080/ 2>/dev/null || echo "${DIM}پایین${RST}"
  printf "  %-14s " "تست:8090"; curl -s -o /dev/null -m 3 -w '%{http_code}\n' http://127.0.0.1:8090/ 2>/dev/null || echo "${DIM}پایین${RST}"
  printf "  %-14s " "php در PATH"; command -v php >/dev/null 2>&1 && echo "${GRN}$(command -v php)${RST}" || echo "${RED}نیست (--start را اجرا کنید)${RST}"
}

# -----------------------------------------------------------------------------
main() {
  local stages=()
  for a in "$@"; do
    case "$a" in
      --force)  FORCE=1 ;;
      --status) status; exit 0 ;;
      --serve)  serve; exit 0 ;;
      --test)   run_tests; exit $? ;;
      --start)  load_env; start_redis; start_mariadb; link_php; start_test_server; status; exit 0 ;;
      -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
      *) stages+=("$a") ;;
    esac
  done
  [ ${#stages[@]} -eq 0 ] && stages=("${STAGES_ALL[@]}")
  local t0=$SECONDS
  for s in "${stages[@]}"; do "stage_$s" || die "مرحله‌ی $s شکست خورد"; done
  echo
  say "تمام شد در $(( (SECONDS-t0)/60 )) دقیقه و $(( (SECONDS-t0)%60 )) ثانیه"
  status
  echo
  echo "  برای بالا آوردن سایت:  ${GRN}bash scripts/provision.sh --serve${RST}"
  echo "  برای اجرای تست‌ها:     ${GRN}bash scripts/provision.sh --test${RST}"
}
main "$@"
