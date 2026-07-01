<?php
/**
 * bec_inventory_reference.php
 * Canonical BEC equipment categories and campus/building/room locations,
 * transcribed from the official PMO INVENTORY LIST (Sept 2025).
 *
 * Used to populate the defect-report form's Category and Location pickers so
 * they reflect real BEC assets even before the equipment table is fully loaded.
 */

if (!function_exists('becCategories')) {
    /** Equipment categories present in the BEC PMO inventory (+ common report types). */
    function becCategories(): array {
        return [
            'Air Conditioner',
            'Television',
            'Electric Fan',
            'Whiteboard / Glassboard',
            'Locker',
            'Cabinet',
            'Office Table',
            'Office Chair',
            'Copier / Duplicator',
            'Piano',
            'Food Warmer',
            'Computer',
            'Printer',
            'Projector',
            'Network Equipment',
            'Lighting / Electrical',
            'Plumbing / Sanitary',
            'Other / Not sure',
        ];
    }
}

if (!function_exists('becLocationTree')) {
    /**
     * Campus => Building label => [rooms/areas].
     * Mirrors the PMO inventory's location hierarchy.
     */
    function becLocationTree(): array {
        return [
            'Main Campus' => [
                'Main Gate (Entrance)' => ['Guard House'],
                'Building 1 - Gymnasium' => [
                    'Basketball Court', 'Stage', 'Storage',
                    'Control Room 1 / Sound System', 'Control Room 2 / RISO Room',
                    'Backstage - Principal\'s Office',
                ],
                'Building 2 - Faculty & Student Center' => [
                    'College Faculty Office', 'Student-Teacher Area',
                ],
                'Building 3 - Learning Resource Building' => [
                    'Junior Highschool Faculty Office', 'Room 125',
                    'Learning Resource Center / Library', 'Male & Female Faculty CR',
                ],
                'Building 4 - Diamond Building (3-Storey HS)' => [
                    'Diamond Room 101', 'Diamond Room 102', 'Diamond Room 201',
                    'Diamond Room 202', 'Diamond Room 301', 'Diamond Room 302',
                    'HS Guidance Office', 'Deans Office', 'SSO',
                    'Ground Floor Corridor', 'Second Floor Corridor', 'Third Floor Corridor',
                ],
                'Building 5 - TLE Building' => [
                    'TLE / HE Laboratory (incl. T&B)', 'TLE Room 126',
                    'Robotics Room (Room 126)', 'Computer Laboratory', 'Genyo Laboratory',
                ],
                'Building 6 - Canteen & Support Services Center' => [
                    'Delivery Area', 'Kitchen', 'Clinic', 'Service & Dining Area', 'Storage',
                    'Male Comfort Room', 'Female Comfort Room', 'Registrars Office',
                    'Finance Office / Accounting Office', 'Cashier', 'Genset Room',
                ],
                'Building 7 - Old HS Building' => [
                    'Room 108', 'Room 109', 'Room 110', 'Room 111', 'Room 112', 'Room 113',
                    'Room 114', 'Room 115', 'Electrical Room', 'Storage 1', 'Storage 2',
                    'Computer Laboratory', 'Genyo Laboratory',
                    'Girls Comfort Room', 'Boys Comfort Room',
                ],
                'Building 9 - Temporary Building 2' => ['Room 124'],
                'Building 10 - Temporary Building 3' => [
                    'Room 103', 'Room 104', 'Room 105', 'Room 106', 'Room 107',
                ],
                'Building 11 - Bookstore / Kiosk' => ['Bookstore / Kiosk'],
                'Building 22 - Temporary Building' => ['Room 116', 'Room 117'],
                'Back Gate (Exit)' => ['Guard House'],
            ],

            'Annex 1 Campus' => [
                'Main Gate (Entrance)' => ['Guard House'],
                'Building 12 - Admin Services Building' => [
                    'Bookstore', 'Bookstore Storage', 'Purchasing Office', 'ITSO', 'HRDO',
                    'Records Room', 'Storage Room', 'RISO Room',
                ],
                'Building 13 - BEC Skills Training Center' => [
                    'Kitchen / Lab Storage (Cookery Lab)', 'FBS Laboratory',
                    'Reception Area & Front Office Lab', 'Hotel Room (Housekeeping Laboratory)',
                    'Laundry Room', 'BSTC 101', 'BSTC 102',
                    'CSS NC II Laboratory (Software Lab)', 'CSS NC II Storage / Office (Technical Lab)',
                    'DCNT Laboratory', 'TESDA Accreditation Office',
                    'BSTC Rm 201', 'BSTC Rm 202', 'BSTC Rm 203 (Computer Lab)',
                    'BSTC Rm 204', 'BSTC Rm 205', 'BSTC Rm 206',
                    'BSTC Rm 301', 'BSTC Rm 302', 'BSTC Rm 303', 'BSTC Rm 304', 'BSTC Rm 305', 'BSTC Rm 306',
                    'BSTC Rm 401', 'BSTC Rm 402', 'BSTC Rm 403', 'BSTC Rm 404',
                    'BSTC Rm 405 - Laboratory', 'BSTC Rm 406 - Laboratory',
                    'Conference Room', 'Corporate Communications Office / Exe. Com',
                    'VPAA Office', 'Office of the School Administrator', 'SHS Faculty',
                    'Science Laboratory Storage Room',
                ],
                'Building 14 - Annex Canteen' => [
                    'Kitchen', 'Storage', 'Service Area', 'Dining Area',
                ],
                'Building 15 - Pre-school Building' => [
                    'Nursery 1', 'Nursery 2', 'Kindergarten Learning Center',
                ],
                'Building 16 - Multi-purpose Hall' => [
                    'Stage', 'Backstage & Storage', 'Badminton Court',
                ],
                'Building 17 - Grade School Building 1' => [
                    'Office of the Principal', 'Faculty Office', 'Computer / Genyo Laboratory',
                    'Classroom 1 - Gr. 1 Ash', 'Classroom 2 - Gr. 1 Obedience',
                    'Classroom 3 - Gr. 2 Tala', 'Classroom 4 - Gr. 2 Respect',
                    'Classroom 5 - Gr. 7 Genesis', 'Classroom 5 - Gr. 7 Exodus',
                    'Gr. 3 Humility', 'Library - Gr. 4 Honesty', 'Bahay Alumni', 'Clinic',
                ],
                'Building 18 - Grade School Building 2' => [
                    'Portable Room Gr. 3 - Andy', 'Portable Room Gr. 4 - Bob',
                    'Portable Room Gr. 5 - Sunny', 'Classroom 6 - Bikoy',
                ],
                'Building 20 - Temporary Warehouse' => ['Temporary Warehouse'],
                'Back Gate (Exit)' => ['Guard House'],
            ],

            'Annex 2 Campus' => [
                'Building 21 - Annex 2 Temporary Building' => [
                    'Classroom 127', 'Classroom 128', 'Classroom 129', 'Classroom 130',
                    'Classroom 131', 'Classroom 132', 'Classroom 133', 'Classroom 134',
                    'Bookstore',
                ],
                'SPC Building - TESDA' => [
                    'SPC RM. 101', 'SPC RM. 102', 'SPC RM. 103', 'SPC RM. 104',
                    'SPC RM. 105', 'SPC RM. 106', 'SPC RM. 107',
                    'SPC RM. 201', 'SPC RM. 202', 'SPC RM. 203', 'SPC RM. 204',
                    'TESDA-Faculty', 'Lounge', 'Office of the President', 'Clinic',
                    'Bar Tending Laboratory', 'Bookkeeping NC III Assessment Room',
                    'Tool Room (Classroom 205)', 'E-PASS Laboratory',
                    'Visual Graphic Design Laboratory', 'Housekeeping Storage',
                ],
                'Rental Bldg. - GA Building - SHS' => [
                    'GA 101', 'GA 102', 'GA 103', 'GA 201', 'GA 202', 'GA 203', 'Office',
                ],
            ],
        ];
    }
}

if (!function_exists('becLocations')) {
    /** Flat, human-readable list of every room/area: "Campus • Building • Room". */
    function becLocations(): array {
        $out = [];
        foreach (becLocationTree() as $campus => $buildings) {
            foreach ($buildings as $building => $rooms) {
                foreach ($rooms as $room) {
                    $out[] = $campus . ' • ' . $building . ' • ' . $room;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('becCampuses')) {
    function becCampuses(): array {
        return array_keys(becLocationTree());
    }
}
