<?php
namespace App\Libs\fake\composer;

class Text
{
    /**
     * @var string
     */
    protected $text;

    /**
     * @var string
     */
    protected $family;

    /**
     * @var int
     */
    protected $size;

    /**
     * @var Color $color
     */
    protected $color;

    /**
     * @var Position $position;
     */
    protected $position;

    /**
     * @return string
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @param string $text
     */
    public function setText(string $text)
    {
        $this->text = $text;
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return $this->family;
    }

    /**
     * @param string $family
     */
    public function setFamily(string $family)
    {
        $this->family = $family;
    }

    /**
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @param int $size
     */
    public function setSize(int $size)
    {
        $this->size = $size;
    }

    /**
     * @return Color
     */
    public function getColor()
    {
        return $this->color;
    }

    /**
     * @param Color $color
     * @return Text
     */
    public function setColor(Color $color)
    {
        $this->color = $color;
        return $this;
    }

    /**
     * @param Position $position
     * @return Text
     */
    public function setPosition(Position $position)
    {
        $this->position = $position;
        return $this;
    }

    /**
     * @return Position
     */
    public function getPosition()
    {
        return $this->position;
    }

    public static function create()
    {
        $text = new static();

        $text->setPosition(new Position(0, 0));
        $text->setColor(new Color(0, 0, 0));

        return $text;
    }
}
