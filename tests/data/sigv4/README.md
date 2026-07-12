# SigV4 fixture notes
#
# Signature vectors are built at runtime by FilesS3SigV4RequestBuilder
# using the same UriEncode / HMAC rules as filesS3SignatureV4.
# Static golden files are optional; prefer in-test builders so region/date stay current.
