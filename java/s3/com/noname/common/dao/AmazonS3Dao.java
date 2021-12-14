package com.noname.common.dao;

import com.amazonaws.services.s3.model.*;
import java.util.List;

/**
 * @author ****
 * @since *****
 * Code snippet for demo purpose
 * All customer-sensitive information was removed on purpose
 */
public interface AmazonS3Dao {
    S3Object downloadFileFromAmazon(String bucketName, String path);

    boolean uploadFileToS3(PutObjectRequest putObjectRequest);

    List<S3ObjectSummary> getFilesFromAmazon(String bucket, String prefix);

    boolean copyAmazonFile(CopyObjectRequest request);

    boolean deleteAmazonFile(DeleteObjectRequest request);

    boolean isCertificateFileExist(String bucket, String prefix);
}
