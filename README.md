
# karls Slug Bundle for unite cms

Provides a slug Field for Unite-Cms.

## Installation

via composer
```
    composer require karls/slug-bundle
```

in config/bundles.php

```
Karls\SlugBundle\KarlsSlugBundle::class => ['all' => true],
```


## Usage


#### Slug

```
{
  "type": "slug",
  "settings": {
    "source": "title"
  }
}
```