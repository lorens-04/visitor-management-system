<?php

/**
 * @return array<string, string> code => full name
 */
function appointment_office_map(): array
{
    return [
        "CCI" => "College of Computing and Informatics",
        "COED" => "College of Education",
        "CEA" => "College of Engineering and Architecture",
        "CIT" => "College of Industrial Technology",
        "CAS" => "College of Arts and Sciences",
    ];
}

function appointment_office_label(string $code): string
{
    $map = appointment_office_map();
    return $map[$code] ?? $code;
}
