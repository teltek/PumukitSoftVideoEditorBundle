# Soft Video Editor Bundle

This bundle adds a soft-editing video editor for Multimedia Objects. If installed, there will be a new tag on the multimedia objects back-office to add breaks, chapter marks or set a trimming to a multimedia object.

This bundle requires having installed the [Paella Player Bundle](https://github.com/teltek/PumukitPaellaPlayerBundle)

Preview:

![video editor](Resources/video_doc.jpg)

How to install bundle
```bash
composer require teltek/pumukit-soft-video-editor-bundle
```

if not, add this to config/bundles.php

```
Pumukit/SoftVideoEditorBundle/PumukitSoftVideoEditorBundle::class => ['all' => true]
```

Then execute the following commands

```bash
php bin/console cache:clear
php bin/console cache:clear --env=prod
php bin/console assets:install
```
