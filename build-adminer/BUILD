#!/usr/bin/env rc

flag e +

rm -f adminer-mysql-en.php
rm -rf build/
mkdir build/
mkdir build/processed
mkdir -p build/externals/jush/modules

	# dummy files because we don't like the JUSH
touch build/externals/jush/modules/jush.js
touch build/externals/jush/modules/jush- ^ (textarea txt js sql pgsql sqlite mssql oracle simpledb) ^ .js

touch build/externals/jush/jush.css


dir=../../adminer.git

arching --process-include \
	--inline-files-when '#lzw_decompress[(]compile_file[(](.+), ''[^'']+''[)][)]#' \
	--transform-include '#include "../adminer/lang/[$]LANG.inc.php";#' 'include "../adminer/lang/en.inc.php";' \
	--override-dir ./build/externals \
	-- $dir/adminer/index.php \
	> adminer-mysql-en.php

BUILD_VERSION=`{uuidgen}

sed -i -r 's#href="(../adminer/static/[^"]+)"#href="<?= htmlspecialchars(ME .''file='' .rawurlencode(basename(''\1'')) .''\&version='^$BUILD_VERSION^''')?>"#g' adminer-mysql-en.php

sed -i -r 's#"([.][.]/adminer/static/functions[.]js)"#ME .''file='' .basename(&) .''\&version='^$BUILD_VERSION^'''#' adminer-mysql-en.php
sed -i -r 's#"(static/editing[.]js)"#ME .''file='' .basename(&)#' adminer-mysql-en.php

du -ks adminer-mysql-en.php
