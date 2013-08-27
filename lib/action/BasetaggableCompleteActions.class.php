<?php

/**
 * Extend this in your own override of taggableCompleteActions if you wish to override, let's say,
 * getQuery() without losing everything else
 *
 * @package    sfDoctrineActAsTaggable
 * @subpackage taggableComplete
 * @author     Tom Boutell, P'unk Avenue, www.punkave.com
 *
 */
 
class BasetaggableCompleteActions extends sfActions
{
  /**
   * Default index action: nothing to see here move along
   *
   */
  public function executeIndex()
  {
    $this->forward404();
  }
  /**
   * Tag typeahead AJAX. You might want to secure this action to prevent
   * information discovery in some cases
   *
   */
  public function executeComplete(sfWebRequest $request)
  {
    $this->setLayout(false);

    // Simplified version for the newer tag editor which does not
    // send a string with all the current tags

    $oldJquery = $request->hasParameter('q');
    $newJquery = $request->hasParameter('term');
    if ($oldJquery || $newJquery) {
      if ($oldJquery) {
        $current = $request->getParameter('q');
      } else {
        $current = $request->getParameter('term');
      }
      $this->tagSuggestions = array();
      $tag = Doctrine_Query::create()->
        from('Tag t')->
        where('t.name = ?', $current)->
        fetchOne();
      if ($tag) {
        $this->tagSuggestions[] = array('suggested' => $tag['name']);
      }
      $q = $this->getQuery($current);
      $suggestedTags = $q->execute();
      foreach ($suggestedTags as $suggestion) {
        if ($suggestion['name'] === $tag['name']) {
          continue;
        }
        $this->tagSuggestions[] = array('suggested' => $suggestion['name']);
      }
      if ($oldJquery)
      {
        $this->setTemplate('jQueryAutocompleteOld');
      }
      elseif ($newJquery)
      {
        $this->setTemplate('jQueryAutocomplete');
      }
      return;
    }

  	$current = $request->getParameter('current');
    $tags = array();
    $tagsInfo = array();
    $tagsAll = array();
    while (preg_match(
      "/^(([\s\,]*)([^\,]+?)(\s*(\,|$)))(.*)$/", $current, $matches)) 
    {
      list($dummy, $all, $left, $tagName, $right, $dummy, $current) = $matches;
      $tagsInfo[] = array(
        'left' => $left,
        'name' => $tagName,
        'right' => $right
      );
      $tagsAll[] = $all;
    }
    $this->tagSuggestions = array();
    $all = '';
    $n = 0;
    $presentOrSuggested = array();
    foreach ($tagsInfo as $tagInfo) {
      $tag = Doctrine_Query::create()->
        from('Tag t')->
        where('t.name = ?', $tagInfo['name'])->
        fetchOne();
      $all .= $tagInfo['left'];
      if ($tag) {
        $presentOrSuggested[$tagInfo['name']] = true;
      } else {
        // $suggestedTags = sfTagtoolsToolkit::getBeginningWith($tagInfo['name']);
        $q = $this->getQuery($tagInfo['name']);
        $suggestedTags = $q->execute();
        foreach ($suggestedTags as $tag) {
          if (isset($presentOrSuggested[$tag->getName()])) {
            continue;
          }
          // At least some browsers actually submitted the
          // nonbreaking spaces as ordinals distinct from regular spaces,
          // producing distinct tags. So leave the spaces alone.

          // Also, we no longer display 'left' visibly anyway because 
          // that was never compatible with a list of tags that required scrolling

          $suggestion['left'] = $all;
          $suggestion['suggested'] = $tag->getName();
          $presentOrSuggested[$tag->getName()] = true;
          $suggestion['right'] = 
            $tagInfo['right'] . implode('', array_slice($tagsAll, $n + 1));
          $this->tagSuggestions[] = $suggestion;
        }
      }
      $all .= $tagInfo['name'];
      $all .= $tagInfo['right'];
      $n++;
    }
  }

  // Override point
  public function getQuery($partialName)
  {
    $q = Doctrine_Query::create()->
      from('Tag t')->
      where('t.name LIKE ?', $partialName . '%')->
      limit(sfConfig::get('app_sfDoctrineActAsTaggable_max_suggestions', 10));
    return $q;
  }

	public function executeAddTag(sfWebRequest $request)
	{
		$object_id = $request->getParameter('object_id');
		$object_class = $request->getParameter('object_class');

		$this->forward404unless($object_id && $object_class);

		$object = Doctrine_Core::getTable($object_class)->findOneBy('id', $object_id);

		$tags = $request->getParameter('tags');

		//TODO: Wrap this in some permission check
		if(!method_exists($object, 'userHasPrivilege') || $object->userHasPrivilege('edit'))
		{
			$object->addTag($tags);
			$object->save();
		} else
		{
			$this->forward404();
		}

		return $this->renderComponent('taggableComplete', 'tagWidget', array('object' => $object));

	}

	public function executeRemoveTag(sfWebRequest $request)
	{
		$object_id = $request->getParameter('object_id');
		$object_class = $request->getParameter('object_class');

		$this->forward404unless($object_id && $object_class);

		$object = Doctrine_Core::getTable($object_class)->findOneBy('id', $object_id);

		$tags = $request->getParameter('tags');

		//TODO: Wrap this in some permission check
		if(!method_exists($object, 'userHasPrivilege') || $object->userHasPrivilege('edit'))
		{
			$object->removeTag($tags);
			$object->save();
		} else
		{
			$this->forward404();
		}

		return $this->renderComponent('taggableComplete', 'tagWidget', array('object' => $object));
	}
}

