#!/bin/sh
echo 'Creating database dump on server...'
ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 '/usr/bin/mysqldump --add-drop-table radadata | /bin/gzip > /home/ubuntu/radadata_prod.sql.gz'

echo 'Downloading dump...'
scp -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180:/home/ubuntu/radadata_prod.sql.gz ~/Downloads/radadata_prod.sql.gz

echo 'Applying dump to local database...'
gunzip < ~/Downloads/radadata_prod.sql.gz | mysql -u root -proot radadata


#echo 'Compressing downloads cache on server...'
#ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 'cd /vol/; tar -zcf downloads.tar.gz downloads'
#
#echo 'Downloading archive...'
#scp -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180:/vol/downloads.tar.gz ~/www/radadata/downloads.tar.gz
#
#echo 'Extracting downloads cache to local file system...'
#rm -rf ~/www/radadata/downloads
#cd ~/www/radadata/
#tar -zxf downloads.tar.gz