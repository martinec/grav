<?php
namespace Grav\Common\Page\Medium;

class VideoMedium extends Medium
{
    use StaticResizeTrait;

    /**
     * Parsedown element for source display mode
     *
     * @param  array $attributes
     * @param  boolean $reset
     * @return array
     */
    protected function sourceParsedownElement(array $attributes, $reset = true)
    {
        $location = $this->url($reset);

        return [
            'name' => 'video',
            'text' => '<source src="' . $location . '">Your browser does not support the video tag.',
            'attributes' => $attributes
        ];
    }

    /**
     * Reset medium.
     *
     * @return $this
     */
    public function reset()
    {
        parent::reset();

        $this->attributes['controls'] = true;
        return $this;
    }
}
