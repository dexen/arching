<?php

namespace {
function Aaa() { return 2; }

Aaa();
}

#require 'extra1.php'; <?php

namespace Foo {

function FooAaa() { return 33; }

}

namespace {
Aaa();
FooAaa();
}
