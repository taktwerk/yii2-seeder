<?php

if (!function_exists('loop')) {
    /**
     * @param callable $handle
     * @param integer $count
     */
    function loop(callable $handle, int $count)
    {
        if ($count > 0) {
            foreach (range(1, $count) as $i) {
                if ($handle($i, $count) === false) {
                    break;
                }
            }
        }
    }

    if (!function_exists('last')) {
        /**
         * Get the last element from an array.
         *
         * @param array $array
         * @return mixed
         */
        function last(array $array)
        {
            return end($array);
        }
    }
}

if (!function_exists('get_faker_identity')) {
    function get_faker_identity($faker) {
        $lastName = $faker->lastName;
        $firstName = $faker->firstName;
        $userName = $lastName.'.'.$firstName;
        $fullName = $firstName . ' ' . $lastName;
        $email = strtolower($userName) . '@' . explode('@', $faker->email)[1];

        return ['lastName' => $lastName, 'firstName' => $firstName, 'fullName' => $fullName, 'email' => $email, 'userName' => $userName];
    }
}