<?php

namespace MadeSimple\Validator;

use MadeSimple\Arrays\Arr;
use MadeSimple\Arrays\ArrDots;

class Validator
{
    /**
     * @var string
     */
    protected $lang;

    /**
     * @var string
     */
    protected $langDir;

    /**
     * @var array Associative array of rule name to callable
     */
    protected $rules;

    /**
     * @var array Associative array of rule name to message
     */
    protected $messages;

    /**
     * @var array
     */
    protected $errors;

    /**
     * Validator constructor.
     *
     * @param string $lang
     * @param string $langDir
     */
    public function __construct($lang = 'en', $langDir = __DIR__ . '/lang/')
    {
        $this->lang    = $lang;
        $this->langDir = $langDir;
        $this->reset();
    }

    /**
     * Set the rules of this Validator.
     *
     * @param string $lang
     * @param string $langDir
     * @return Validator
     */
    public function setLanguage($lang = 'en', $langDir = __DIR__ . '/lang/')
    {
        $this->lang    = $lang;
        $this->langDir = $langDir;

        $langFile = realpath($langDir . $lang . '.php');
        if (!file_exists($langFile)) {
            throw new \InvalidArgumentException('No such file: ' . $langDir . $lang . '.php');
        }

        $callable = require $langFile;
        $callable($this);

        return $this;
    }

    /**
     * Set the message for the rule with the given name.
     *
     * @param string $name
     * @param string $message
     * @return \MadeSimple\Validator\Validator
     */
    public function setRuleMessage(string $name, string $message)
    {
        $this->messages['rules'][$name] = $message;
        return $this;
    }

    /**
     * Set the message for the attribute with the given name.
     *
     * @param string $name
     * @param string $message
     * @return \MadeSimple\Validator\Validator
     */
    public function setAttributeMessage(string $name, string $message)
    {
        $this->messages['custom'][$name] = $message;
        return $this;
    }

    /**
     * Add a new rule to the validator.
     * ```php
     * function (Validator $validator, array $data, $pattern, $rule, array $parameters) {
     *     foreach ($validator->getValues($data, $pattern) as $attribute => $value) {
     *         if (null === $value) {
     *             continue;
     *         }
     *         if (in_array($value, $parameters)) {
     *             continue;
     *         }
     *
     *         $validator->addError($attribute, $rule, [':values' => implode(', ', $parameters)]);
     *     }
     * }
     * ```
     *
     * @param string $name
     * @param callable $callable
     * @return \MadeSimple\Validator\Validator
     * @see \MadeSimple\Validator\Validator::setRuleMessage()
     */
    public function addRule(string $name, callable $callable)
    {
        $this->rules[$name] = $callable;
        return $this;
    }

    /**
     * Resets the validator to its initial state.
     *
     * @return Validator
     */
    public function reset()
    {
        // Remove all rules and messages
        $this->rules = [];
        $this->messages = ['rules' => [], 'custom' => []];
        $this->clear();

        // Add the initial rules and messages
        Validate::addRuleSet($this);
        $this->setLanguage($this->lang, $this->langDir);

        return $this;
    }

    /**
     * Clear the validator of all errors.
     *
     * @return Validator
     */
    public function clear()
    {
        $this->errors = [];

        return $this;
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return count($this->errors) > 0;
    }

    /**
     * Return a processed array of errors.
     *
     * @return array
     */
    public function getProcessedErrors()
    {
        $errors = [];

        foreach ($this->errors as $error) {
            // Process replacements
            $message = ArrDots::get($this->messages['custom'], $error['attribute'])
                       ?? ArrDots::get($this->messages['rules'], $error['rule']);
            foreach ($error['replacements'] as $search => $replace) {
                switch ($search[0]) {
                    case ':':
                        $message = str_replace($search, Str::prettyAttribute($replace), $message);
                        break;
                    case '!':
                        if (!$replace) {
                            break;
                        }
                        // Check if the attribute is singular (use group 1) or plural (use group 2)
                        // Group 2 if plural, group 1 if singular
                        $replace = substr($error['replacements'][':attribute'] ?? '', -1, 1) !== '*'
                            ? '$1' : '$2';
                        $message = preg_replace("/$search/", $replace, $message);
                        break;

                    case '%':
                    default:
                        $message = str_replace($search, $replace, $message);
                        break;
                }
            }
            $errors[$error['attribute']][$error['rule']] = $message;
        }

        return ['errors' => $errors];
    }

    /**
     * @param array|null|object $values
     * @param array             $ruleSet
     *
     * @return bool
     */
    public function validate($values, array $ruleSet) : bool
    {
        // If there are no rules, there is nothing to validate
        if(empty($ruleSet)) {
            return true;
        }

        // For each pattern and its rules
        foreach ($ruleSet as $pattern => $rules) {
            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }
            foreach ($rules as $rule) {
                list($rule, $parameters) = array_pad(explode(':', $rule, 2), 2, '');
                $parameters = array_map('trim', explode(',', $parameters));

                if (Arr::exists($this->rules, $rule)) {
                    call_user_func($this->rules[$rule], $this, $values, $pattern, $rule, $parameters);
                }
            }
        }

        return $this->hasErrors();
    }

    /**
     * @param string      $attribute
     * @param string      $rule
     * @param array       $replacements
     */
    public function addError($attribute, $rule, $replacements = [])
    {
        $replacements = array_merge([
            ':attribute'    => $attribute,
            '!(\S+)\|(\S+)' => true,
        ], $replacements ?? []);

        $this->errors[] = [
            'attribute'    => $attribute,
            'rule'         => $rule,
            'replacements' => $replacements,
        ];
    }

    /**
     * @param array  $array
     * @param string $pattern
     *
     * @return \Generator
     */
    public static function getValues(&$array, $pattern)
    {
        foreach (ArrDots::collate($array, $pattern, '*') as $attribute => $value) {
            yield $attribute => $value;
        }
    }

    /**
     * @param array $array
     * @param string $pattern
     *
     * @return mixed|null First matching value or null
     */
    public static function getValue(&$array, $pattern)
    {
        $imploded = ArrDots::implode($array);
        $pattern  = sprintf('/^%s$/', str_replace('*', '[0-9]+', $pattern));

        foreach ($imploded as $attribute => $value) {
            if (preg_match($pattern, $attribute) == 0) {
                continue;
            }

            return $value;
        }

        return null;
    }
}