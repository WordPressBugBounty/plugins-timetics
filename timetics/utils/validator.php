<?php
/**
 * Validator Traits
 *
 * @package Timetics
 */
namespace Timetics\Utils;

use WP_Error;

/**
 * Validator trait
 */
trait Validator {
    /**
     * Store WP_Error Object
     *
     * @var WP_Error
     */
    private $error;

    /**
     * Validation rules
     *
     * @var array
     */
    private $rules = ['required', 'min', 'max', 'email', 'timezone'];

    /**
     * Validation error messages
     *
     * @var array
     */
    private $messages = [
        'required' => ':field is required',
        'min'      => ':field is minimum of :satisfier',
        'max'      => ':field is maximum of :satisfier',
        'email'    => 'This is email is invalid',
        'timezone' => 'This is not valid timezone',
    ];

    /**
     * Validate fields
     *
     * @param   array  $rules   The rules that will be compared with field value
     * @param   array  $fields  Input fields
     *
     * @return  bool | WP_Error
     */
    public function validate( $rules, $fields ) {
        $this->error = new WP_Error();

        foreach ( $fields as $field => $value ) {
            if ( array_key_exists( $field, $rules ) ) {
                $this->check( [
                    'field' => $field,
                    'value' => $value,
                    'rules' => $rules[$field],
                ] );
            }
        }

        if ( $this->error->has_errors() ) {
            return $this->error;
        }

        return true;
    }

    /**
     * Check the rules with field value
     *
     * @param   array  $item
     *
     * @return  void
     */
    private function check( $item ) {
        $field = $item['field'];
        $value = $item['value'];
        $rules = $item['rules'];

        foreach ( $rules as $rule => $satisfier ) {
            if ( ! in_array( $rule, $this->rules ) ) {
                continue;
            }

            if ( ! call_user_func_array( [$this, $rule], [$field, $value, $satisfier] ) ) {
                $code    = $item['field'] . '_' . $rule;
                $message = str_replace( [':field', ':satisfier'], [$field, $satisfier], $this->messages[$rule] );

                $this->error->add( $code, $message );
            }
        }
    }

    /**
     * Required validation
     *
     * @return bool
     */
    private function required( $field, $value, $satisfier ) {
        return ! empty( $value );
    }

    /**
     * Minimum length validation
     *
     * @return bool
     */
    private function min( $field, $value, $satisfier ) {
        return mb_strlen( $value ) >= $satisfier;
    }

    /**
     * Maximum length validation
     *
     * @return bool
     */
    private function max( $field, $value, $satisfier ) {
        return mb_strlen( $value ) <= $satisfier;
    }

    /**
     * Email validation
     *
     * @return bool
     */
    private function email( $field, $value, $satisfier ) {
        return filter_var( $value, FILTER_VALIDATE_EMAIL );
    }

    /**
     * Validate timezone
     *
     * @return  bool
     */
    private function timezone( $field, $value, $satisfier ) {
        try {
            new \DateTimeZone( $value );
        } catch ( \Exception $e ) {
            return false;
        }

        return true;
    }
}
