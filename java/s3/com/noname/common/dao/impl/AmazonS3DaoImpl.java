package com.noname.common.dao.impl;

import com.amazonaws.SdkClientException;
import com.amazonaws.services.s3.AmazonS3Client;
import com.amazonaws.services.s3.model.*;
import com.matrixx.common.dao.AmazonS3Dao;
import com.matrixx.model.general.MtxException;
import com.matrixx.model.general.ResponseCode;
import com.matrixx.util.Constants;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.stereotype.Component;

import java.util.List;

/**
 * @author ****
 * @since *****
 * Code snippet is intended for demo
 * All customer-sensitive information was removed on purpose
 */
@Component
public class AmazonS3DaoImpl implements AmazonS3Dao {
    private static final Logger logger = LoggerFactory.getLogger(AmazonS3DaoImpl.class);

    @Autowired
    private AmazonS3Client amazonS3Client;

    @Override
    public S3Object downloadFileFromAmazon(String bucketName, String path) {
        return amazonS3Client.getObject(bucketName, path);
    }

    @Override
    public boolean uploadFileToS3(PutObjectRequest putObjectRequest) {
        try {
            amazonS3Client.putObject(putObjectRequest);
        } catch (SdkClientException e) {
            logger.error("Failed to upload file to Amazon S3", e);
            return false;
        }

        return true;
    }

    @Override
    public List<S3ObjectSummary> getFilesFromAmazon(String bucket, String prefix) {
        try {
            ListObjectsV2Request request = new ListObjectsV2Request().withBucketName(bucket);
            if (prefix != null) {
                request = request.withPrefix(prefix + "/");
            }

            return amazonS3Client.listObjectsV2(request).getObjectSummaries();
        } catch (SdkClientException e) {
            logger.error("Failed to upload file to Amazon S3", e);
            throw new MtxException(ResponseCode.AMAZON_S3_ERROR, "Error receiving files list from amazon s3");
        }
    }

    @Override
    public boolean copyAmazonFile(CopyObjectRequest request) {
        try {
            amazonS3Client.copyObject(request);
        } catch (SdkClientException e) {
            logger.error("Failed to copy file in Amazon S3", e);
            return false;
        }

        return true;
    }

    @Override
    public boolean deleteAmazonFile(DeleteObjectRequest request) {
        try {
            amazonS3Client.deleteObject(request);
        } catch (SdkClientException e) {
            logger.error("Failed to delete file from Amazon S3", e);
            return false;
        }

        return true;
    }

    @Override
    public boolean isCertificateFileExist(String bucket, String prefix) {
       return getFilesFromAmazon(bucket, prefix)
                    .stream()
                    .filter(obj -> obj.getSize() != 0)
                    .map(S3ObjectSummary::getKey)
                    .anyMatch(path -> path.endsWith(Constants.CERTIFICATE_FILENAME));
    }
}
