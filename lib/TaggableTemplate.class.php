<?php
/*
 * This file is part of the sfDoctrineActAsTaggablePlugin package.
 * But is fully inspired by sfPropelActAsTaggableBehavior package.
 *
 * (c) 2007 Xavier Lacot <xavier@lacot.org> - sfPropelActAsTaggableBehavior
 * (c) 2008 Mickael Kurmann <mickael.kurmann@gmail.com> - sfDoctrineActAsTaggablePlugin
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * This behavior permits to attach tags to Doctrine objects. Some more bits about
 * the philosophy of the stuff:
 *
 * - taggable objects must have a primary key
 * - tags are saved when the object is saved, not before
 *
 * - one object cannot be tagged twice with the same tag. When trying to use
 *   twice the same tag on one object, the second tagging will be ignored
 *
 * - the tags associated to one taggable object are only loaded when necessary.
 *   Then they are cached.
 *
 * - once created, tags never change in the Tag table. When using replaceTag(),
 *   a new tag is created if necessary, but the old one is not deleted.
 *
 *
 * The plugin associates a parameterHolder to Propel objects, with 3 namespaces:
 *
 * - tags:
 *     Tags that have been attached to the object, but not yet saved.
 *     Contract: tags are disjoin of (saved_tags union removed_tags)
 *
 * - saved_tags:
 *     Tags that are presently saved in the database
 *
 * - removed_tags:
 *     Tags that are presently saved in the database, but which will be removed
 *     at the next save()
 *     Contract: removed_tags are disjoin of (tags union saved_tags)
 *
 *
 * @author   Xavier Lacot <xavier@lacot.org>
 * @see      http://www.symfony-project.com/trac/wiki/sfDoctrineActAsTaggablePlugin
 */
class TaggableListener extends Doctrine_Record_Listener
{
    /**
     * Tags saving logic, runned after the object himself has been saved
     *
     * @param      Doctrine_Event  $event
     */
    public function postSave(Doctrine_Event $event)
    {
        $object = $event->getInvoker();

        $added_tags = Taggable::get_tags($object);
        $removed_tags = array_keys(Taggable::get_removed_tags($object));

        // save new tags
        foreach ($added_tags as $tagname)
        {
            $tag = Doctrine_Core::getTable('Tag')
              ->findOrCreateByTagName($tagname);
            $tag->save();

            $tagging = new Tagging();
            $tagging->tag_id = $tag->id;
            $tagging->taggable_id = $object->id;
            $tagging->taggable_model = $this->getClassnameToReturn($object);
            $tagging->save();

            $tag->free();
            $tagging->free();
            unset($tag, $tagging);
        }

        if($removed_tags)
        {
            $q = Doctrine_Query::create()->select('t.id')
                ->from('Tag t INDEXBY t.id')
                ->whereIn('t.name', $removed_tags);

            $removed_tag_ids = array_keys($q->execute(array(), Doctrine_Core::HYDRATE_ARRAY));

            Doctrine_Core::getTable('Tagging')->createQuery()
	            ->delete()
	            ->whereIn('tag_id', $removed_tag_ids)
	            ->addWhere('taggable_id = ?', $object->id)
	            ->addWhere('taggable_model = ?', get_class($object))
	            ->execute();
        }

        $tags = (Taggable::get_tags($object) + $object->getSavedTags());

        Taggable::set_saved_tags($object, $tags);
        Taggable::clear_tags($object);
        Taggable::clear_removed_tags($object);
    }

    /**
     * Delete related Taggings when this object is deleted
     *
     * @param      Doctrine_Event $event
     */
    public function preDelete(Doctrine_Event $event)
    {
        $object = $event->getInvoker();

        Doctrine_Core::getTable('Tagging')->createQuery()
          ->delete()
          ->addWhere('taggable_id = ?')
          ->addWhere('taggable_model = ?')
          ->execute(array($object->id, get_class($object)));
    }

    /**
     * Get the class name of the current record while taking into account inheritance
     *
     * @see Doctrine_Table::getClassNameToReturn()
     * @param Doctrine_Record $record
     * @return string
     */
    public function getClassnameToReturn($record)
    {
        $table = $record->getTable();
        if ( ! $table->getOption('subclasses')) {
            return $table->getOption('name');
        }
        foreach ($table->getOption('subclasses') as $subclass) {
            $subclassTable = Doctrine_Core::getTable($subclass);
            $inheritanceMap = $subclassTable->getOption('inheritanceMap');
            $nomatch = false;
            foreach ($inheritanceMap as $key => $value) {
                if ( ! $record->contains($key) || $record->get($key) != $value) {
                    $nomatch = true;
                    break;
                }
            }
            if ( ! $nomatch) {
                return $subclassTable->getComponentName();
            }
        }
        return $table->getOption('name');
    }
}

class Taggable extends Doctrine_Template
{
    public function setTableDefinition()
    {
        $this->addListener(new TaggableListener());
    }

    /**
     * parameterHolder access methods
     */
    public static function getTagsHolder($object)
    {
        if ((!isset($object->_tags)) || ($object->_tags == null))
        {
            if (class_exists('sfNamespacedParameterHolder'))
            {
                // Symfony 1.1
                $parameter_holder = 'sfNamespacedParameterHolder';
            }
            else
            {
                // Symfony 1.0
                $parameter_holder = 'sfParameterHolder';
            }

            $object->mapValue('_tags', new $parameter_holder());
        }

        return $object->_tags;
    }

    public static function add_tag($object, $tag, $options = array())
    {
        $tag = TaggableToolkit::cleanTagName($tag, $options);

        if (strlen($tag) > 0)
        {
            self::getTagsHolder($object)->set($tag, $tag, 'tags');
        }
    }

    public static function clear_tags($object)
    {
        return self::getTagsHolder($object)->removeNamespace('tags');
    }

    public static function get_tags($object)
    {
        return self::getTagsHolder($object)->getAll('tags');
    }

    public static function set_tags($object, $tags)
    {
        self::clear_tags($object);
        self::getTagsHolder($object)->add($tags, 'tags');
    }

    public static function add_saved_tag($object, $tag)
    {
        self::getTagsHolder($object)->set($tag, $tag, 'saved_tags');
    }

    public static function clear_saved_tags($object)
    {
        return self::getTagsHolder($object)->removeNamespace('saved_tags');
    }

    public static function get_saved_tags($object)
    {
        return self::getTagsHolder($object)->getAll('saved_tags');
    }

    public static function set_saved_tags($object, $tags = array())
    {
        self::clear_saved_tags($object);
        self::getTagsHolder($object)->add($tags, 'saved_tags');
    }

    public static function add_removed_tag($object, $tag)
    {
        self::getTagsHolder($object)->set($tag, $tag, 'removed_tags');
    }

    public static function clear_removed_tags($object)
    {
        return self::getTagsHolder($object)->removeNamespace('removed_tags');
    }

    public static function get_removed_tags($object)
    {
        return self::getTagsHolder($object)->getAll('removed_tags');
    }

    public static function set_removed_tags($object, $tags)
    {
        self::clear_removed_tags($object);
        self::getTagsHolder($object)->add($tags, 'removed_tags');
    }

    /**
    * Adds a tag to the object. The "tagname" param can be a string or an array
    * of strings. These 3 code sequences produce an equivalent result :
    *
    * 1- $object->addTag('tag1,tag2,tag3');
    * 2- $object->addTag('tag1');
    *    $object->addTag('tag2');
    *    $object->addTag('tag3');
    * 3- $object->addTag(array('tag1','tag2','tag3'));
    *
    * @param      mixed       $tagname
    */
    public function addTag($tagname, $options = array())
    {
        if ('' == $tagname)
        {
          return;
        }

        $tagname = TaggableToolkit::explodeTagString($tagname);

        if (is_array($tagname))
        {
            foreach ($tagname as $tag)
            {
                $this->addTag($tag, $options);
            }
        }
        else
        {
            $removed_tags = $this->get_removed_tags($this->getInvoker()) ;

            if (isset($removed_tags[$tagname]))
            {
                unset($removed_tags[$tagname]);
                $this->set_removed_tags($this->getInvoker(), $removed_tags);
                $this->add_saved_tag($this->getInvoker(), $tagname);
            }
            else
            {
                $saved_tags = $this->getSavedTags();

                if (sfConfig::get('app_sfDoctrineActAsTaggablePlugin_triple_distinct', false))
                {
                    // the binome namespace:key must be unique
                    $triple = TaggableToolkit::extractTriple($tagname);

                    if (!is_null($triple[1]) && !is_null($triple[2]))
                    {
                        $tags = $this->getTags(array('triple' => true, 'return' => 'tag'));
                        $pattern = '/^'.$triple[1].':'.$triple[2].'=(.*)$/';
                        $removed = array();

                        foreach ($tags as $tag)
                        {
                            if (preg_match($pattern, $tag))
                            {
                              $removed[] = $tag;
                            }
                        }

                        $this->removeTag($removed);
                    }
                }

                if (!isset($saved_tags[$tagname]))
                {
                    $this->add_tag($this->getInvoker(), $tagname, $options);
                }
            }
        }
    }

    /**
    * Retrieves from the database tags that have been atached to the object.
    * Once loaded, this saved tags list is cached and updated in memory.
    */
    public function getSavedTags()
    {
        $option = $this->getTagsHolder($this->getInvoker());

        if (!isset($option) || !$option->hasNamespace('saved_tags'))
        {
            // if record is new
            if ($this->getInvoker()->state() === Doctrine_Record::STATE_TCLEAN)
            {
                $this->set_saved_tags($this->getInvoker(), array());
                return array();
            }
            else
            {
                $q = Doctrine_Query::create()
                  ->select('t.name')
                  ->from('Tag t INDEXBY t.name, t.Tagging tg')
                  ->addWhere('tg.taggable_id = ?')
                  ->addWhere('tg.taggable_model = ?');

                $saved_tags = $q->execute(array($this->getInvoker()->id, get_class($this->getInvoker())), Doctrine_Core::HYDRATE_ARRAY);

                $tags = array();
                foreach ($saved_tags as $key => $infos)
                {
                    $tags[$key] = $key;
                }

                $this->set_saved_tags($this->getInvoker(), $tags);

                return $tags;
            }
        }
        else
        {
            return $this->get_saved_tags($this->getInvoker()) ;
        }
    }

    /**
    * Returns the list of the tags attached to the object, whatever they have
    * already been saved or not.
    *
    * @param       $object
    */
    public function getTags($options = array())
    {
        $tags = ($this->get_tags($this->getInvoker()) + $this->getSavedTags());

        if (isset($options['is_triple']) && (true === $options['is_triple']))
        {
            $tags = array_map(array('TaggableToolkit', 'extractTriple'), $tags);
            $pattern = array('tag', 'namespace', 'key', 'value');

            foreach ($pattern as $key => $value)
            {
                if (isset($options[$value]))
                {
                    $tags_array = array();

                    foreach ($tags as $tag)
                    {
                        if ($tag[$key] == $options[$value])
                        {
                            $tags_array[] = $tag;
                        }
                    }

                    $tags = $tags_array;
                }
            }

            $return = (isset($options['return']) && in_array($options['return'], $pattern)) ? $options['return'] : 'all';

            if ('all' != $return)
            {
                $keys = array_flip($pattern);
                $tags_array = array();

                foreach ($tags as $tag)
                {
                    if (null != $tag[$keys[$return]])
                    {
                        $tags_array[] = $tag[$keys[$return]];
                    }
                }

                $tags = array_unique($tags_array);
            }
        }

        if (!isset($return) || ('all' != $return))
        {
            ksort($tags);

            if (isset($options['serialized']) && (true === $options['serialized']))
            {
                $tags = implode(', ', $tags);
            }
        }

        return $tags;
    }

    /**
    * Returns true if the object has a tag. If a tag ar an array of tags is
    * passed in second parameter, checks if these tags are attached to the object
    *
    * These 3 calls are equivalent :
    * 1- $object->hasTag('tag1')
    *    && $object->hasTag('tag2')
    *    && $object->hasTag('tag3');
    * 2- $object->hasTag('tag1,tag2,tag3');
    * 3- $object->hasTag(array('tag1', 'tag2', 'tag3'));
    *
    * @param      mixed       $tag
    */
    public function hasTag($tag = null)
    {
        $tag = TaggableToolkit::explodeTagString($tag);

        if (is_array($tag))
        {
            $result = true;

            foreach ($tag as $tagname)
            {
                $result = $result && $this->hasTag($tagname);
            }

            return $result;
        }
        else
        {
            $tags = $this->get_tags($this->getInvoker()) ;

            if ($tag === null)
            {
                return (count($tags) > 0) || (count($this->getSavedTags()) > 0);
            }
            elseif (is_string($tag))
            {
                $tag = TaggableToolkit::cleanTagName($tag);

                if (isset($tags[$tag]))
                {
                    return true;
                }
                else
                {
                    $saved_tags = $this->getSavedTags();
                    $removed_tags = $this->get_removed_tags($this->getInvoker()) ;
                    return isset($saved_tags[$tag]) && !isset($removed_tags[$tag]);
                }
            }
            else
            {
                $msg = sprintf('hasTag() does not support this type of argument : %s.', get_class($tag));
                throw new Exception($msg);
            }
        }
    }

    /**
    * Preload tags for a set of objects. It might be usefull in case you want to
    * display a long list of taggable objects with their associated tags: it
    * avoids to load tags per object, and gets all tags in a few requests.
    *
    * @param      array       $objects
    */
    public static function preloadTags(&$objects)
    {
      $searched = array();

      foreach($objects as $object)
      {
        $class = get_class($object);
        if(!isset($searched[$class]))
        {
          $searched[$class] = array();
        }

        $searched[$class][$object->getPrimaryKey()] = $object;
        Taggable::set_saved_tags($object, array());
      }

      $q = Doctrine_Core::getTable('Tagging')->createQuery('t')
        ->leftJoin('t.Tag as tag')
        ->orderBy('t.taggable_id')
        ->setHydrationMode(Doctrine_Core::HYDRATE_ARRAY);

      foreach($searched as $model => $instances)
      {
        $qClone = clone $q;
        $taggings = $qClone
          ->addWhere('t.taggable_model = ?', $model)
          ->andWhereIn('t.taggable_id', array_keys($instances))
          ->execute();

        foreach($taggings as $tagging)
        {
          Taggable::add_saved_tag($instances[$tagging['taggable_id']], $tagging['Tag']['name']);
        }
      }
    }

    /**
    * Removes all the tags associated to the object.
    *
    * @param       $object
    */
    public function removeAllTags()
    {
        $saved_tags = $this->getSavedTags();

        $this->set_saved_tags($this->getInvoker(), array());
        $this->set_tags($this->getInvoker(), array());
        $this->set_removed_tags($this->getInvoker(), ($this->get_removed_tags($this->getInvoker()) + $saved_tags));
    }

    /**
    * Removes a tag or a set of tags from the object. The
    * parameter might be an array of tags or a comma-separated string.
    *
    * @param      mixed       $tagname
    */
    public function removeTag($tagname)
    {
        $tagname = TaggableToolkit::explodeTagString($tagname);

        if (is_array($tagname))
        {
            foreach ($tagname as $tag)
            {
                $this->removeTag($tag);
            }
        }
        else
        {
            $tagname = TaggableToolkit::cleanTagName($tagname);

            $tags = $this->get_tags($this->getInvoker()) ;
            $saved_tags = $this->getSavedTags();

            if (isset($tags[$tagname]))
            {
              unset($tags[$tagname]);
              $this->set_tags($this->getInvoker(), $tags);
            }

            if (isset($saved_tags[$tagname]))
            {
                unset($saved_tags[$tagname]);
                $this->set_saved_tags($this->getInvoker(), $saved_tags);
                $this->add_removed_tag($this->getInvoker(), $tagname);
            }
        }
    }

    /**
    * Replaces a tag with an other one. If the third optionnal parameter is not
    * passed, the second tag will simply be removed
    *
    * @param       $object
    * @param      String      $tagname
    * @param      String      $replacement
    */
    public function replaceTag($tagname, $replacement = null)
    {
        if (($replacement != $tagname) && ($tagname != null))
        {
            $this->removeTag($tagname);

            if ($replacement != null)
            {
                $this->addTag($replacement);
            }
        }
    }

    /**
    * Sets the tags of an object. As usual, the second parameter might be an
    * array of tags or a comma-separated string.
    *
    * @param       $object
    * @param      mixed       $tagname
    */
    public function setTags($tagname)
    {
        $this->removeAllTags();
        $this->addTag($tagname);
    }
}