<?php
// test variables definition
define('TEST_CLASS', getenv('SYMFONY_TAGGABLE_TEST_CLASS') ?: 'Logger');
define('TEST_CLASS_2', getenv('SYMFONY_TAGGABLE_TEST_CLASS_2') ?: 'Alert');
define('TEST_NON_TAGGABLE_CLASS', getenv('SYMFONY_TAGGABLE_TEST_NON_TAG_CLASS') ?: 'Event');

require_once dirname(__DIR__) . '/bootstrap/unit.php';

$all = \sfConfig::getAll();
if (!defined('TEST_CLASS') || !class_exists(TEST_CLASS)
    || !defined('TEST_CLASS_2') || !class_exists(TEST_CLASS_2))
{
  // Don't run tests
  $t = new lime_test(1, new lime_output_color());
  $t->fail('test classes not configured');

  return;
}

// clean the database
Doctrine_Query::create()->delete()->from('Tag')->execute();
Doctrine_Query::create()->delete()->from('Tagging')->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS)->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS_2)->execute();

// create a new test browser
// $browser = new sfTestBrowser();
// $browser->initialize();

// start tests
$t = new lime_test(50, new lime_output_color());

// these tests check for the tags attachement consistency
$t->diag('tagging consistency');

$object = _create_object();
$t->ok($object->getTags() == array(), 'a new object has no tag.');

$object->addTag('toto');
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 1) && ($object_tags[0] == 'toto'), 'a non-saved object can get tagged.');

$object->addTag('toto');
$object_tags = $object->getTags();
$t->ok(count($object_tags) == 1, 'a tag is only applied once to non-saved objects.');
$object->save();

$object->addTag('toto');
$object_tags = $object->getTags();
$t->ok(count($object_tags) == 1, 'a tag is also only applied once to saved objects.');
$object->save();

$object->addTag('tutu');
$object_tags = $object->getTags();
$t->ok($object->hasTag('tutu'), 'a saved object can get tagged.');
$object->save();

// get the key of this object
$id1 = $object->getPrimaryKey();

$object->removeTag('tutu');
$t->ok(!$object->hasTag('tutu'), 'a previously saved tag can be removed.');

$object->addTag('tata');
$object->removeTag('tata');
$t->ok(!$object->hasTag('tata'), 'a non-saved tag can also be removed.');

$object->addTag('tu\'tu');
$t->ok($object->hasTag('tu\'tu'), 'a tag can contain a quote.');

$object2 = _create_object();
$object_tags = $object2->getTags();
$t->ok(count($object_tags) == 0, 'a new object has no tag, even if other tagged objects exist.');
$object2->save();

$object2->addTag('titi');
$t->ok($object2->hasTag('titi'), 'a new object can get tagged, even if other tagged objects exist.');
$t->ok(!$object->hasTag('titi'), 'tags applied to new objects do not affect old ones.');
$object2->save();
$id2 = $object2->getPrimaryKey();

$object = _create_object();
$object->addTag('tutu');
$object->addTag('titi');
$object->save();
$object->addTag('tata');
$object->removeAllTags();
$t->ok(!$object->hasTag(), 'tags can all be removed at once.');

$object = _create_object();
$object->addTag('toto,international,tata');
$object->save();
$id = $object->getPrimaryKey();
$object = Doctrine_Core::getTable(TEST_CLASS)->findOneById($id);
$object->removeTag('tata');
$object->addTag('tata');
$object->save();
$object = Doctrine_Core::getTable(TEST_CLASS)->findOneById($id);
$object_tags = $object->getTags();
$t->ok(count($object_tags) == 3, 'when removing one previously saved tag, then restoring it, and then saving it again, this tag is not duplicated.');

$object = _create_object();
$object->addTag('toto,tutu,tata');
$object->save();
$object->removeAllTags();
$object->addTag('toto,tutu,tata');
$object->save();
$id = $object->getPrimaryKey();
$object = Doctrine_Core::getTable(TEST_CLASS)->findOneById($id);
$object_tags = $object->getTags();
$t->ok(count($object_tags) == 3, 'when removing all previously saved tags, then restoring it, and then saving it again, tags are not duplicated.');

$object = _create_object();
$object->addTag('toto,tutu,tata');
$object->save();
$previous_count = count($object->getTags());
$object->removeAllTags();
$object->save();
$id = $object->getPrimaryKey();
$object = Doctrine_Core::getTable(TEST_CLASS)->findOneById($id);
$t->ok(($previous_count == 3) && !$object->hasTag(), 'previously in-database tags can be deleted.');

$object = _create_object();
$object->addTag('toto, tutu, test');
$object->save();
$id = $object->getPrimaryKey();
$object = Doctrine_Core::getTable(TEST_CLASS)->findOneById($id);

$object2 = _create_object();
$object2->addTag('clever age, symfony, test');
$object2->save();
$object2->removeTag('test');
$object2->save();

$object_tags = $object->getTags();
$object2_tags = $object2->getTags();
$t->ok((count($object2_tags) == 2) && (count($object_tags) == 3), 'removing one tag as no effect on the other tags of the object, neither on the other objects.');

$object2_tags = $object2->getTags(array('serialized' => true));
$t->ok($object2_tags == 'clever age, symfony', 'tags can be retrieved in a serialized form.');

$object->removeAllTags();
$object->setTags('');
$object->save();
$id = $object->getPrimaryKey();
$object = Doctrine_Core::getTable(TEST_CLASS)->findOneById($id);
$t->ok(count($object->getTags()) == 0, 'when the tags are set twice, or all removed twice, before the object is saved, then all the prvious tags are still removed.');

unset($object, $object2, $object2_copy);


// these tests check the various methods for applying tags to an object
$t->diag('various methods for applying tags');
$object = _create_object();
$object->addTag('toto');
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 1) && ($object_tags[0] == 'toto'), 'one tag can be added alone.');

$object->addTag('titi,tutu');
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 3) && $object->hasTag('tutu') && $object->hasTag('titi'), 'tags can be added with a comma-separated string.');
$t->ok($object->hasTag('titi, tutu'), 'comma-separated strings are divided into several tags.');

$object = _create_object();
$object->addTag('titi
tutu');
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 2) && $object->hasTag('titi') && $object->hasTag('tutu'), 'tags can be added using line breaks as separators.');
$t->ok($object->hasTag('titi
tutu'), 'line-breaks-separated strings are divided into several tags.');

$object = _create_object();
$object->addTag('titi

tutu');
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 2) && $object->hasTag('titi') && $object->hasTag('tutu'), 'when adding tags using line breaks as separators, remove blank lines.');

$object = _create_object();
$object->addTag(array('titi', 'tutu'));
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 2) && $object->hasTag('tutu') && $object->hasTag('titi'), 'tags can be added with an array.');

$object->setTags('wallace, gromit');
$object_tags = $object->getTags();
$t->ok((count($object_tags) == 2) && $object->hasTag('wallace') && $object->hasTag('gromit'), 'tags can be set directly using setTags().');

unset($object);


// these tests check for the tagging removal on object deletion

// clean the database
Doctrine_Query::create()->delete()->from('Tag')->execute();
Doctrine_Query::create()->delete()->from('Tagging')->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS)->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS_2)->execute();

$t->diag('taggings removal on objects deletion');
$object1 = _create_object();
$object1->addTag('tag2,tag3,tag1,tag4,tag5,tag6');
$object1->save();

$object2 = _create_object();
$object2->addTag('tag4,tag7,tag8');
$object2->save();

$object2->delete();

$tags = Doctrine_Core::getTable('Tag')->getAllTagNameWithCount();
$t->ok(isset($tags['tag4']) && !isset($tags['tag7']), 'the taggings associated to one object are deleted when this object is deleted.');


// these tests check for TagPeer methods (tag clouds generation)

// clean the database
Doctrine_Query::create()->delete()->from('Tag')->execute();
Doctrine_Query::create()->delete()->from('Tagging')->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS)->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS_2)->execute();

$t->diag('tag clouds');
$object1 = _create_object();
$object1->addTag('tag2,tag3,tag1,tag4,tag5,tag6');
$object1->save();

$object2 = _create_object();
$object2->addTag('tag1,tag3,tag4,tag7');
$object2->save();

$object3 = _create_object();
$object3->addTag('tag2,tag3,tag7,tag8');
$object3->save();

$object4 = _create_object();
$object4->addTag('tag3');
$object4->save();

$object5 = _create_object();
$object5->addTag('tag1,tag3,tag7');
$object5->save();

// getAllTagName() test
$result = Doctrine_Core::getTable('Tag')->getAllTagName();
$t->ok($result == array('tag1', 'tag2', 'tag3', 'tag4', 'tag5', 'tag6', 'tag7', 'tag8'), 'all tags can be retrieved with getAllTagName().');

// getAllTagNameWithCount() test
$tags = Doctrine_Core::getTable('Tag')->getAllTagNameWithCount();
$t->ok($tags == array('tag1' => 3, 'tag2' => 2, 'tag3' => 5, 'tag4' => 2, 'tag5' => 1, 'tag6' => 1, 'tag7' => 3, 'tag8' => 1), 'all tags can be retrieved with getAllTagName().');

// getPopulars() test
$q = Doctrine_Query::create()->limit(3);
$tags = Doctrine_Core::getTable('Tag')->getPopulars($q);
$t->ok(array_keys($tags) == array('tag1', 'tag3', 'tag7'), 'most popular tags can be retrieved with getPopulars().');
$t->ok($tags['tag3'] >= $tags['tag1'], 'getPopulars() preserves tag importance.');

// getRelatedTags() test
$tags = Doctrine_Core::getTable('Tag')->getRelatedTags('tag8');
$t->ok(array_keys($tags) == array('tag2', 'tag3', 'tag7'), 'related tags can be retrieved with getRelatedTags().');

$tags = Doctrine_Core::getTable('Tag')->getRelatedTags('tag2', array('limit' => 1));
$t->ok(array_keys($tags) == array('tag3'), 'when a limit is set, only most popular related tags are returned by getRelatedTags().');

// getRelatedTags() test
$tags = Doctrine_Core::getTable('Tag')->getRelatedTags('tag7');
$t->ok(array_keys($tags) == array('tag1', 'tag2', 'tag3', 'tag4', 'tag8'), 'getRelatedTags() aggregates tags from different objects.');

// getRelatedTags() test
$tags = Doctrine_Core::getTable('Tag')->getRelatedTags(array('tag2', 'tag7'));
$t->ok(array_keys($tags) == array('tag3', 'tag8'), 'getRelatedTags() can retrieve tags related to an array of tags.');

// getRelatedTags() test
$tags = Doctrine_Core::getTable('Tag')->getRelatedTags('tag2,tag7');
$t->ok(array_keys($tags) == array('tag3', 'tag8'), 'getRelatedTags() also accepts a coma-separated string.');

// getObjectTaggedWith() tests
$object_2_1 = _create_object_2();
$object_2_1->addTag('tag1,tag3,tag7');
$object_2_1->save();

$object_2_2 = _create_object_2();
$object_2_2->addTag('tag2,tag7');
$object_2_2->save();

$tagged_with_tag4 = Doctrine_Core::getTable('Tag')->getObjectTaggedWith('tag4');
$t->ok(count($tagged_with_tag4) == 2, 'getObjectTaggedWith() returns objects tagged with one specific tag.');

$tagged_with_tag7 = Doctrine_Core::getTable('Tag')->getObjectTaggedWith('tag7');
$t->ok(count($tagged_with_tag7) == 5, 'getObjectTaggedWith() can return several object types.');

$tagged_with_tag17 = Doctrine_Core::getTable('Tag')->getObjectTaggedWith(array('tag1', 'tag7'));
$t->ok(count($tagged_with_tag17) == 3, 'getObjectTaggedWith() returns objects tagged with several specific tags.');

$tagged_with_tag127 = Doctrine_Core::getTable('Tag')->getObjectTaggedWith('tag1, tag2, tag7',
                                             array('nb_common_tags' => 2));
$t->ok(count($tagged_with_tag127) == 6, 'the "nb_common_tags" option of getObjectTaggedWith() returns objects tagged with a certain number of tags within a set of specific tags.');


// these tests check the preloadTags() method
Taggable::preloadTags($tagged_with_tag17);
$nb_tags = 0;

foreach ($tagged_with_tag17 as $tmp_object)
{
  $nb_tags += count($tmp_object->getTags());
}

$t->ok($nb_tags === 10, 'preloadTags() preloads the tags of the objects.');


// these tests check the isTaggable() method
$t->diag('detecting if a model is taggable or not');

$t->ok(TaggableToolkit::isTaggable(TEST_CLASS) === true, 'it is possible to tell if a model is taggable from its name.');

$object = _create_object();
$t->ok(TaggableToolkit::isTaggable($object) === true, 'it is possible to tell if a model is taggable from one of its instances.');
$t->ok(TaggableToolkit::isTaggable(TEST_NON_TAGGABLE_CLASS) === false, TEST_NON_TAGGABLE_CLASS.' is not taggable, and that is fine.');

// clean the database
Doctrine_Query::create()->delete()->from('Tag')->execute();
Doctrine_Query::create()->delete()->from('Tagging')->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS)->execute();
Doctrine_Query::create()->delete()->from(TEST_CLASS_2)->execute();


// these tests check for the application of triple tags
$t->diag('applying triple tagging');

$t->ok(TaggableToolkit::extractTriple('ns:key=value') === array('ns:key=value', 'ns', 'key', 'value'), 'triple extracted successfully.');
$t->ok(TaggableToolkit::extractTriple('ns:key') === array('ns:key', null, null, null), 'ns:key is not a triple.');
$t->ok(TaggableToolkit::extractTriple('ns') === array('ns', null, null, null), 'ns is not a triple.');

$object = _create_object();
$object->addTag('tutu');
$object->save();

$object = _create_object();
$object->addTag('ns:key=value');
$object->addTag('ns:key=tutu');
$object->addTag('ns:key=titi');
$object->addTag('ns:key=toto');
$object->save();

$object_tags = $object->getTags();
$t->ok($object->hasTag('ns:key=value'), 'object has triple tag');

$tag = Doctrine_Core::getTable('Tag')->findOrCreateByTagname('ns:key=value');
$t->ok($tag->getIsTriple(), 'a triple tag created from a string is identified as a triple.');

$tag = Doctrine_Core::getTable('Tag')->findOrCreateByTagname('tutu');
$t->ok(!$tag->getIsTriple(), 'a non tripled tag created from a string is not identified as a triple.');

// test object creation
function _create_object()
{
  $classname = TEST_CLASS;

  if (!class_exists($classname))
  {
    throw new Exception(sprintf('Unknow class "%s"', $classname));
  }

  return new $classname();
}

// second type of test object creation
function _create_object_2()
{
  $classname = TEST_CLASS_2;

  if (!class_exists($classname))
  {
    throw new Exception(sprintf('Unknow class "%s"', $classname));
  }

  return new $classname();
}
