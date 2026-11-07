Hello {{ $residentName ?: 'Resident' }},

BarangayCare has an upcoming benefit release for you. This message uses the latest schedule saved by the barangay staff.

Program: {{ $programName }}
Benefit: {{ $benefit }}
Date: {{ $date ?: 'To be announced' }}
Time: {{ $time ?: 'To be announced' }}
Venue: {{ $venue ?: 'To be announced' }}
Amount / Items: {{ $releaseAmount ?: 'To be announced' }}
Assigned Staff: {{ $assignedStaff ?: 'To be announced' }}
Status: {{ $status ?: 'Scheduled' }}

@if ($instructions !== '')
Instructions: {{ $instructions }}

@endif
Please bring a valid ID and any documents the barangay asks for. If this schedule changes, staff will send another email with the updated details.

Thank you,
BarangayCare
