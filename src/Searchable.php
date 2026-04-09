<?php

namespace DartVadius\EloquentSearch;

trait Searchable
{
    abstract public function searchableConfig(): SearchableConfig;
}
