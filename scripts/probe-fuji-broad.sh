#!/usr/bin/env bash
echo "=== Fuji Broad Query (plano: CR + FCR_PANO + 20260609) ==="
docker run --rm --network host darthunix/dcmtk sh -c '
dump2dcm /dev/stdin /tmp/q.dcm <<EOF
# Dicom-Data-Set
(0008,0060) CS [CR]
(0040,0001) AE [FCR_PANO]
(0040,0002) DA [20260609]
EOF
findscu -W -v -aet FCR_PANO -aec SIRESA_MWL 127.0.0.1 4242 /tmp/q.dcm 2>&1
' | grep -E 'PatientName|PatientID|0010,0010|FCR_PANO|Find Response|Success|Failed|Pending' | tail -15

echo ""
echo "=== Fuji Broad Query fecha HOY (20260615) — debe estar vacío ==="
docker run --rm --network host darthunix/dcmtk sh -c '
dump2dcm /dev/stdin /tmp/q.dcm <<EOF
# Dicom-Data-Set
(0008,0060) CS [CR]
(0040,0001) AE [FCR_PANO]
(0040,0002) DA [20260615]
EOF
findscu -W -v -aet FCR_PANO -aec SIRESA_MWL 127.0.0.1 4242 /tmp/q.dcm 2>&1
' | grep -E 'Find Response|Success|Failed|Pending|Patient' | tail -10
