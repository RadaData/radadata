#!/bin/sh
echo 'Creating database backup on server...'
ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 '/usr/bin/mysqldump --add-drop-table radadata | /bin/gzip > /home/ubuntu/radadata_prod.sql.gz'

echo 'Creating local database dump...'
mysqldump --add-drop-table radadata | /usr/bin/gzip > /Users/Admin/Downloads/radadata_dev.sql.gz

echo 'Uploading dump...'
scp -i ~/.ssh/AMI-ROOT.pem ~/Downloads/radadata_dev.sql.gz ubuntu@52.6.63.180:/home/ubuntu/radadata_dev.sql.gz

echo 'Applying database dump on server...'
ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 'gunzip < /home/ubuntu/radadata_dev.sql.gz | mysql -u root -proot radadata'


echo 'Creating downloads cache backup on server...'
ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 'cd /vol/; tar -zcf downloads.tar.gz downloads; rm downloads_bk.tar.gz; mv downloads.tar.gz downloads_bk.tar.gz'

echo 'Compressing local downloads cache...'
cd /Users/Admin/www/radadata/
tar -zcf downloads.tar.gz downloads

echo 'Uploading archive...'
scp -i ~/.ssh/AMI-ROOT.pem ~/www/radadata/downloads.tar.gz ubuntu@52.6.63.180:/vol/downloads.tar.gz

echo 'Extracting downloads cache to a server file system...'
ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 'rm -rf /vol/downloads; cd /vol; tar -zxf downloads.tar.gz'