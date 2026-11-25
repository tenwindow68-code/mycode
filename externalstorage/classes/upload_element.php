<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Custom external-storage upload form element.
 *
 * Does not extend HTML_QuickForm_file.
 */
class assignsubmission_externalstorage_upload_element
    extends HTML_QuickForm_element {

    /**
     * Constructor.
     */
    public function __construct($elementName = null, $elementLabel = null, $attributes = null) {
        parent::__construct($elementName, $elementLabel, $attributes);
    }

    /**
     * Render HTML.
     */
    public function toHtml() {
        $name = $this->getName();

        return html_writer::empty_tag('input', [
            'type' => 'file',
            'name' => $name,
            'id' => $name,
            'class' => 'form-control',
        ]);
    }

    /**
     * Get submitted value.
     */
    public function getValue() {
        return $_FILES[$this->getName()] ?? null;
    }

    /**
     * Set value (not applicable to upload).
     */
    public function setValue($value) {
        // Nothing to do.
    }

    /**
     * Default value.
     */
    public function getFrozenHtml() {
        return $this->toHtml();
    }

    /**
     * Freeze element.
     */
    public function freeze() {
        return false;
    }
}