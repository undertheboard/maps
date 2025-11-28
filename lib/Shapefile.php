<?php
/**
 * Minimal shapefile + DBF reader in pure PHP.
 * Adapted/simplified from public-domain implementations.
 * Supports Polygon / MultiPolygon geometries and DBF attributes.
 * This is not a full GDAL replacement, but it’s enough for precinct polygons.
 */

class SimpleDbfReader
{
    private $fp;
    private $header;
    private $fields = [];
    private $recordLength;
    private $numRecords;

    public function __construct($filename)
    {
        $this->fp = fopen($filename, 'rb');
        if (!$this->fp) {
            throw new Exception("Cannot open DBF file: $filename");
        }
        $this->readHeader();
    }

    private function readHeader()
    {
        // 32-byte header
        $headerRaw = fread($this->fp, 32);
        if (strlen($headerRaw) !== 32) {
            throw new Exception("Invalid DBF header.");
        }
        $this->numRecords = unpack('V', substr($headerRaw, 4, 4))[1];
        $headerLength    = unpack('v', substr($headerRaw, 8, 2))[1];
        $this->recordLength = unpack('v', substr($headerRaw, 10, 2))[1];

        // Field descriptors (32 bytes each) until 0x0D
        $fieldsBytes = $headerLength - 33; // 32 header + 1 terminator
        $fieldsRaw = fread($this->fp, $fieldsBytes);
        $offset = 0;
        while ($offset + 32 <= strlen($fieldsRaw)) {
            $chunk = substr($fieldsRaw, $offset, 32);
            if (ord($chunk[0]) === 0x0D) {
                break;
            }
            $name = rtrim(substr($chunk, 0, 11), "\0 ");
            $type = $chunk[11];
            $length = ord($chunk[16]);
            $decimalCount = ord($chunk[17]);
            $this->fields[] = [
                'name' => $name,
                'type' => $type,
                'length' => $length,
                'decimal' => $decimalCount,
            ];
            $offset += 32;
        }
        // Read 0x0D terminator
        fseek($this->fp, $headerLength, SEEK_SET);
    }

    public function getNumRecords()
    {
        return $this->numRecords;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function readRecord($index)
    {
        if ($index < 0 || $index >= $this->numRecords) {
            return null;
        }
        // Each record: 1 byte deletion flag + fields
        fseek($this->fp, 32 + $this->headerLength() + $index * $this->recordLength, SEEK_SET);
        $raw = fread($this->fp, $this->recordLength);
        if (strlen($raw) < 1) {
            return null;
        }
        $deleted = ($raw[0] === '*');
        if ($deleted) {
            return null; // skip deleted records
        }
        $data = [];
        $offset = 1;
        foreach ($this->fields as $field) {
            $valueRaw = rtrim(substr($raw, $offset, $field['length']));
            $offset += $field['length'];
            $name = $field['name'];
            switch ($field['type']) {
                case 'N':
                case 'F':
                    $valueRaw = trim($valueRaw);
                    if ($valueRaw === '' || $valueRaw === '-') {
                        $value = null;
                    } elseif ($field['decimal'] > 0) {
                        $value = floatval($valueRaw);
                    } else {
                        $value = intval($valueRaw);
                    }
                    break;
                case 'L': // logical
                    $value = ($valueRaw === 'Y' || $valueRaw === 'y' || $valueRaw === 'T' || $valueRaw === 't');
                    break;
                default:
                    $value = rtrim($valueRaw);
                    break;
            }
            $data[$name] = $value;
        }
        return $data;
    }

    private function headerLength()
    {
        // 32 bytes header already read, just reconstruct from fields
        return 32 + count($this->fields) * 32 + 1 - 32;
    }

    public function close()
    {
        if ($this->fp) {
            fclose($this->fp);
        }
    }
}

class SimpleShpReader
{
    private $fp;
    private $shapeType;
    private $bbox;
    private $records = [];

    public function __construct($filename)
    {
        $this->fp = fopen($filename, 'rb');
        if (!$this->fp) {
            throw new Exception("Cannot open SHP file: $filename");
        }
        $this->readHeader();
        $this->readRecords();
    }

    private function readHeader()
    {
        $raw = fread($this->fp, 100);
        if (strlen($raw) !== 100) {
            throw new Exception("Invalid SHP header.");
        }
        $fileCode = unpack('N', substr($raw, 0, 4))[1];
        if ($fileCode !== 9994) {
            throw new Exception("Not a valid shapefile.");
        }
        $this->shapeType = unpack('V', substr($raw, 32, 4))[1];
        $this->bbox = [
            'xmin' => unpack('d', substr($raw, 36, 8))[1],
            'ymin' => unpack('d', substr($raw, 44, 8))[1],
            'xmax' => unpack('d', substr($raw, 52, 8))[1],
            'ymax' => unpack('d', substr($raw, 60, 8))[1],
        ];
    }

    private function readRecords()
    {
        // Records follow header, each: 8-byte header + content
        while (!feof($this->fp)) {
            $header = fread($this->fp, 8);
            if (strlen($header) < 8) {
                break;
            }
            $recNum = unpack('N', substr($header, 0, 4))[1];
            $contentLenWords = unpack('N', substr($header, 4, 4))[1];
            $contentLenBytes = $contentLenWords * 2;
            $content = fread($this->fp, $contentLenBytes);
            if (strlen($content) < 4) {
                break;
            }
            $shapeType = unpack('V', substr($content, 0, 4))[1];

            if ($shapeType === 5) { // Polygon
                $this->records[] = $this->readPolygon($content);
            } elseif ($shapeType === 15) { // PolygonZ
                $this->records[] = $this->readPolygonZ($content);
            } elseif ($shapeType === 3) { // PolyLine (just in case)
                $this->records[] = $this->readPolyLine($content);
            } else {
                // skip other shapes
            }
        }
    }

    private function readPolygon($content)
    {
        // content: shapeType(4) + bbox(32) + numParts(4) + numPoints(4)
        $bbox = unpack('d4', substr($content, 4, 32));
        $numParts = unpack('V', substr($content, 36, 4))[1];
        $numPoints = unpack('V', substr($content, 40, 4))[1];

        $parts = [];
        for ($i = 0; $i < $numParts; $i++) {
            $parts[] = unpack('V', substr($content, 44 + $i*4, 4))[1];
        }

        $pointsOffset = 44 + $numParts * 4;
        $points = [];
        for ($i = 0; $i < $numPoints; $i++) {
            $x = unpack('d', substr($content, $pointsOffset + $i*16, 8))[1];
            $y = unpack('d', substr($content, $pointsOffset + $i*16 + 8, 8))[1];
            $points[] = [$x, $y];
        }

        // Convert parts to rings
        $rings = [];
        for ($p = 0; $p < $numParts; $p++) {
            $start = $parts[$p];
            $end   = ($p === $numParts - 1) ? $numPoints : $parts[$p+1];
            $rings[] = array_slice($points, $start, $end - $start);
        }

        return [
            'type' => 'Polygon',
            'coordinates' => $rings,
        ];
    }

    private function readPolygonZ($content)
    {
        // treat as Polygon ignoring Z/M
        return $this->readPolygon($content);
    }

    private function readPolyLine($content)
    {
        // treat as Polygon-like (no holes) for simplicity
        $bbox = unpack('d4', substr($content, 4, 32));
        $numParts = unpack('V', substr($content, 36, 4))[1];
        $numPoints = unpack('V', substr($content, 40, 4))[1];

        $parts = [];
        for ($i = 0; $i < $numParts; $i++) {
            $parts[] = unpack('V', substr($content, 44 + $i*4, 4))[1];
        }

        $pointsOffset = 44 + $numParts * 4;
        $points = [];
        for ($i = 0; $i < $numPoints; $i++) {
            $x = unpack('d', substr($content, $pointsOffset + $i*16, 8))[1];
            $y = unpack('d', substr($content, $pointsOffset + $i*16 + 8, 8))[1];
            $points[] = [$x, $y];
        }

        $rings = [];
        for ($p = 0; $p < $numParts; $p++) {
            $start = $parts[$p];
            $end   = ($p === $numParts - 1) ? $numPoints : $parts[$p+1];
            $rings[] = array_slice($points, $start, $end - $start);
        }

        return [
            'type' => 'Polygon',
            'coordinates' => $rings,
        ];
    }

    public function getRecords()
    {
        return $this->records;
    }

    public function close()
    {
        if ($this->fp) fclose($this->fp);
    }
}