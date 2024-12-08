<?php

namespace refaltor\efficiencySql;

abstract class Migration
{
    abstract public function up(): void;

    abstract public function down(): void;

    abstract public function hydrate(): void;
}
