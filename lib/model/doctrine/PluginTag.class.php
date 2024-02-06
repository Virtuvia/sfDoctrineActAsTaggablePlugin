<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class PluginTag extends BaseTag
{
    public function __toString(): string
    {
        return $this->name;
    }

    public function getModelsNameTaggedWith()
    {
        return PluginTagTable::getModelsNameTaggedWith($this->name);
    }

    public function getRelated($options = array())
    {
        return PluginTagTable::getRelatedTags($this->name);
    }

    public function getObjectTaggedWith($options = array())
    {
        return PluginTagTable::getObjectTaggedWith($this->name);
    }
}
