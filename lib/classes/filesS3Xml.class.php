<?php

class filesS3Xml
{
    public static function error($code, $message, $resource = '', $request_id = null)
    {
        $request_id = $request_id ?: self::requestId();
        $message = htmlspecialchars($message, ENT_XML1, 'UTF-8');
        $code = htmlspecialchars($code, ENT_XML1, 'UTF-8');
        $resource = htmlspecialchars($resource, ENT_XML1, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Error>'
            . '<Code>' . $code . '</Code>'
            . '<Message>' . $message . '</Message>'
            . '<Resource>' . $resource . '</Resource>'
            . '<RequestId>' . $request_id . '</RequestId>'
            . '</Error>';
    }

    public static function listBuckets($buckets)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Owner><ID>webasyst</ID><DisplayName>Webasyst Files</DisplayName></Owner>'
            . '<Buckets>';

        foreach ($buckets as $bucket) {
            $name = htmlspecialchars($bucket['name'], ENT_XML1, 'UTF-8');
            $date = gmdate('Y-m-d\TH:i:s.000\Z', strtotime($bucket['create_datetime']));
            $xml .= '<Bucket><Name>' . $name . '</Name><CreationDate>' . $date . '</CreationDate></Bucket>';
        }

        return $xml . '</Buckets></ListAllMyBucketsResult>';
    }

    public static function listObjectsV2($bucket, $prefix, $delimiter, $max_keys, $continuation_token, $items, $common_prefixes, $is_truncated, $next_token)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>' . htmlspecialchars($bucket, ENT_XML1, 'UTF-8') . '</Name>'
            . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1, 'UTF-8') . '</Prefix>'
            . '<KeyCount>' . (count($items) + count($common_prefixes)) . '</KeyCount>'
            . '<MaxKeys>' . (int) $max_keys . '</MaxKeys>'
            . '<IsTruncated>' . ($is_truncated ? 'true' : 'false') . '</IsTruncated>';

        if ($delimiter !== '') {
            $xml .= '<Delimiter>' . htmlspecialchars($delimiter, ENT_XML1, 'UTF-8') . '</Delimiter>';
        }
        if ($continuation_token !== '') {
            $xml .= '<ContinuationToken>' . htmlspecialchars($continuation_token, ENT_XML1, 'UTF-8') . '</ContinuationToken>';
        }
        if ($is_truncated && $next_token !== '') {
            $xml .= '<NextContinuationToken>' . htmlspecialchars($next_token, ENT_XML1, 'UTF-8') . '</NextContinuationToken>';
        }

        foreach ($common_prefixes as $cp) {
            $xml .= '<CommonPrefixes><Prefix>' . htmlspecialchars($cp, ENT_XML1, 'UTF-8') . '</Prefix></CommonPrefixes>';
        }

        foreach ($items as $item) {
            $xml .= '<Contents>';
            $xml .= '<Key>' . htmlspecialchars($item['key'], ENT_XML1, 'UTF-8') . '</Key>';
            $xml .= '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', strtotime($item['last_modified'])) . '</LastModified>';
            $xml .= '<ETag>"' . $item['etag'] . '"</ETag>';
            $xml .= '<Size>' . (int) $item['size'] . '</Size>';
            $xml .= '<StorageClass>STANDARD</StorageClass>';
            $xml .= '</Contents>';
        }

        return $xml . '</ListBucketResult>';
    }

    public static function listObjectsV1($bucket, $prefix, $delimiter, $max_keys, $marker, $items, $common_prefixes, $is_truncated, $next_marker)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>' . htmlspecialchars($bucket, ENT_XML1, 'UTF-8') . '</Name>'
            . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1, 'UTF-8') . '</Prefix>'
            . '<Marker>' . htmlspecialchars($marker, ENT_XML1, 'UTF-8') . '</Marker>'
            . '<MaxKeys>' . (int) $max_keys . '</MaxKeys>'
            . '<IsTruncated>' . ($is_truncated ? 'true' : 'false') . '</IsTruncated>';

        if ($delimiter !== '') {
            $xml .= '<Delimiter>' . htmlspecialchars($delimiter, ENT_XML1, 'UTF-8') . '</Delimiter>';
        }
        if ($is_truncated && $next_marker !== '') {
            $xml .= '<NextMarker>' . htmlspecialchars($next_marker, ENT_XML1, 'UTF-8') . '</NextMarker>';
        }

        foreach ($common_prefixes as $cp) {
            $xml .= '<CommonPrefixes><Prefix>' . htmlspecialchars($cp, ENT_XML1, 'UTF-8') . '</Prefix></CommonPrefixes>';
        }

        foreach ($items as $item) {
            $xml .= '<Contents>';
            $xml .= '<Key>' . htmlspecialchars($item['key'], ENT_XML1, 'UTF-8') . '</Key>';
            $xml .= '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', strtotime($item['last_modified'])) . '</LastModified>';
            $xml .= '<ETag>"' . $item['etag'] . '"</ETag>';
            $xml .= '<Size>' . (int) $item['size'] . '</Size>';
            $xml .= '<StorageClass>STANDARD</StorageClass>';
            $xml .= '</Contents>';
        }

        return $xml . '</ListBucketResult>';
    }

    public static function deleteObjects($deleted, $errors)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';

        foreach ($deleted as $key) {
            $xml .= '<Deleted><Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key></Deleted>';
        }
        foreach ($errors as $error) {
            $xml .= '<Error>'
                . '<Key>' . htmlspecialchars($error['key'], ENT_XML1, 'UTF-8') . '</Key>'
                . '<Code>' . htmlspecialchars($error['code'], ENT_XML1, 'UTF-8') . '</Code>'
                . '<Message>' . htmlspecialchars($error['message'], ENT_XML1, 'UTF-8') . '</Message>'
                . '</Error>';
        }

        return $xml . '</DeleteResult>';
    }

    public static function copyObjectResult($etag, $last_modified = null)
    {
        if ($last_modified === null) {
            $last_modified = gmdate('Y-m-d\TH:i:s.000\Z');
        }
        $etag = htmlspecialchars($etag, ENT_XML1, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<LastModified>' . $last_modified . '</LastModified>'
            . '<ETag>"' . $etag . '"</ETag>'
            . '</CopyObjectResult>';
    }

    public static function initiateMultipartUpload($bucket, $key, $upload_id)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1, 'UTF-8') . '</Bucket>'
            . '<Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key>'
            . '<UploadId>' . htmlspecialchars($upload_id, ENT_XML1, 'UTF-8') . '</UploadId>'
            . '</InitiateMultipartUploadResult>';
    }

    public static function completeMultipartUpload($bucket, $key, $etag, $location = '')
    {
        if ($location === '') {
            $location = '/' . $bucket . '/' . $key;
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Location>' . htmlspecialchars($location, ENT_XML1, 'UTF-8') . '</Location>'
            . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1, 'UTF-8') . '</Bucket>'
            . '<Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key>'
            . '<ETag>"' . $etag . '"</ETag>'
            . '</CompleteMultipartUploadResult>';
    }

    public static function listParts($bucket, $key, $upload_id, $parts)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1, 'UTF-8') . '</Bucket>'
            . '<Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key>'
            . '<UploadId>' . htmlspecialchars($upload_id, ENT_XML1, 'UTF-8') . '</UploadId>'
            . '<IsTruncated>false</IsTruncated>'
            . '<MaxParts>1000</MaxParts>'
            . '<PartNumberMarker>0</PartNumberMarker>';

        foreach ($parts as $part) {
            $xml .= '<Part>'
                . '<PartNumber>' . (int) $part['part_number'] . '</PartNumber>'
                . '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', strtotime($part['last_modified'])) . '</LastModified>'
                . '<ETag>"' . $part['etag'] . '"</ETag>'
                . '<Size>' . (int) $part['size'] . '</Size>'
                . '</Part>';
        }

        return $xml . '</ListPartsResult>';
    }

    protected static function requestId()
    {
        return substr(md5(uniqid('', true)), 0, 16);
    }
}
