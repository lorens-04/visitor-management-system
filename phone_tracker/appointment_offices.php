<?php

/**
 * @return array<string, string> code => full name
 */
function appointment_office_map(): array
{
    return [
        "IT" => "IT Department",
        "IS" => "IS Department",
        "CS" => "CS Department",
        "DEANS" => "Dean's Office",
        "TECH_SUPPORT" => "Tech Support",
    ];
}

function appointment_office_label(string $code): string
{
    $map = appointment_office_map();
    if (isset($map[$code])) {
        return $map[$code];
    }

    // Preserve readable labels for historical records without allowing these legacy
    // codes on new appointments.
    $legacy = [
        "CCI" => "College of Computing and Informatics (legacy)",
        "COED" => "College of Education (legacy)",
        "CEA" => "College of Engineering and Architecture (legacy)",
        "CIT" => "College of Industrial Technology (legacy)",
        "CAS" => "College of Arts and Sciences (legacy)",
    ];
    return $legacy[$code] ?? $code;
}
