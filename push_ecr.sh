VER=0.2

docker tag cedelis/alma-inventory-purdue:${VER} ${AWS_ACCOUNT}.dkr.ecr.us-east-2.amazonaws.com/alma-inventory-purdue:${VER}
docker push ${AWS_ACCOUNT}.dkr.ecr.us-east-2.amazonaws.com/alma-inventory-purdue:${VER}
