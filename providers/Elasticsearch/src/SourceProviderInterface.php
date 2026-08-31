<?php

namespace HawaiianSearch;

interface SourceProviderInterface
{
    public function getCapabilities(): SourceCapabilities;
}
