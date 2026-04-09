<?php

namespace Shifton\EloquentSearch;

trait Searchable
{
    abstract public function searchableConfig(): SearchableConfig;
}
