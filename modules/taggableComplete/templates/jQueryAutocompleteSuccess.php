<?php $tags = array() ?>
<?php foreach ($sf_data->getRaw('tagSuggestions') as $tagInfo): ?>
  <?php $tags[] = $tagInfo['suggested'] ?>
<?php endforeach ?>
<?php echo json_encode($tags) ?>
